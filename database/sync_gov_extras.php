<?php
/**
 * sync_gov_extras.php
 *
 * Enriquece governadores com dados oficiais do TSE:
 *   1. Fotos oficiais (foto_cand2022_{UF}_div.zip)
 *   2. Patrimônio declarado (bem_candidato_2022.zip)
 *   3. Redes sociais (rede_social_candidato_2022_{UF}.zip)
 *   4. Mandato 2022: votos, coligação, resultado (consulta_cand_2022.zip)
 *
 * Uso:
 *   php database/sync_gov_extras.php              — tudo, todos os estados
 *   php database/sync_gov_extras.php PE MG        — estados específicos
 *   php database/sync_gov_extras.php --no-fotos   — pula fotos
 *   php database/sync_gov_extras.php --no-bens    — pula patrimônio
 *   php database/sync_gov_extras.php --no-redes   — pula redes sociais
 *   php database/sync_gov_extras.php --no-mandato — pula dados de mandato
 *   php database/sync_gov_extras.php --no-votos   — pula votos por mandato
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$args      = array_slice($argv, 1);
$noFotos   = in_array('--no-fotos',   $args);
$noBens    = in_array('--no-bens',    $args);
$noRedes   = in_array('--no-redes',   $args);
$noMandato = in_array('--no-mandato', $args);
$noVotos   = in_array('--no-votos',   $args);
$ufsArg    = array_values(array_filter($args, fn($a) => strlen($a) === 2 && ctype_alpha($a)));

$pdo       = Database::connect();
$uploadDir = ROOT . '/public/uploads/parlamentares/governadores';

$rows = $pdo->query(
    "SELECT sapl_id, uf, tse_sq, nome_parlamentar FROM parl_parlamentares
     WHERE source_key='governadores' AND tse_sq IS NOT NULL ORDER BY uf"
)->fetchAll(PDO::FETCH_ASSOC);

if ($ufsArg) {
    $rows = array_values(array_filter($rows, fn($r) => in_array($r['uf'], $ufsArg)));
}

echo "[gov-extras] " . count($rows) . " governadores\n\n";

// ─────────────────────────────────────────────────────────────────────────────

function curlDownload(string $url, string $dest, int $timeout = 120): bool {
    $fp = fopen($dest, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    $size = file_exists($dest) ? filesize($dest) : 0;
    if ($code !== 200 || $size < 1000) { @unlink($dest); return false; }
    return true;
}

function parseBRL(string $val): float {
    // "1.234.567,89" → 1234567.89
    return (float)str_replace(',', '.', str_replace('.', '', trim($val)));
}

function detectPlatform(string $url): string {
    return match(true) {
        str_contains($url, 'instagram') => 'instagram',
        str_contains($url, 'facebook')  => 'facebook',
        str_contains($url, 'twitter')   => 'twitter',
        str_contains($url, 'x.com')     => 'twitter',
        str_contains($url, 'youtube')   => 'youtube',
        str_contains($url, 'tiktok')    => 'tiktok',
        str_contains($url, 'linkedin')  => 'linkedin',
        default                         => 'outro',
    };
}

// ── 1. Fotos oficiais do TSE ──────────────────────────────────────────────────

if (!$noFotos) {
    echo "=== Fotos oficiais TSE ===\n";

    $stFoto = $pdo->prepare(
        "UPDATE parl_parlamentares SET fotografia_url=? WHERE source_key='governadores' AND sapl_id=?"
    );

    foreach ($rows as $r) {
        $govId = (int)$r['sapl_id'];
        $uf    = $r['uf'];
        $sq    = (string)$r['tse_sq'];
        $nome  = $r['nome_parlamentar'];

        $dest   = $uploadDir . '/' . $govId . '.jpg';
        $zipTmp = sys_get_temp_dir() . "/tse_fotos_{$uf}.zip";
        $zipUrl = "https://cdn.tse.jus.br/estatistica/sead/eleicoes/eleicoes2022/fotos/foto_cand2022_{$uf}_div.zip";

        if (!file_exists($zipTmp) || filesize($zipTmp) < 10000) {
            echo "  Baixando ZIP de {$uf}... ";
            if (!curlDownload($zipUrl, $zipTmp)) { echo "ERRO\n"; continue; }
            echo round(filesize($zipTmp) / 1024) . "KB\n";
        }

        $za = new ZipArchive();
        if ($za->open($zipTmp) !== true) { echo "  - {$nome} ({$uf}): falha ao abrir ZIP\n"; continue; }

        $content = $za->getFromName("F{$uf}{$sq}_div.jpg");
        if ($content === false) $content = $za->getFromName("F{$uf}{$sq}_div.jpeg");
        $za->close();

        if ($content === false || strlen($content) < 5000) {
            echo "  - {$nome} ({$uf}): não encontrado no ZIP (sq={$sq})\n";
            continue;
        }

        file_put_contents($dest, $content);
        $localUrl = '/uploads/parlamentares/governadores/' . $govId . '.jpg';
        $stFoto->execute([$localUrl, $govId]);
        echo "  ✓ {$nome} ({$uf}): " . round(strlen($content) / 1024) . "KB\n";
    }
    echo PHP_EOL;
}

// ── 2. Bens declarados / patrimônio ──────────────────────────────────────────

if (!$noBens) {
    echo "=== Patrimônio declarado ===\n";

    $bensUrl = 'https://cdn.tse.jus.br/estatistica/sead/odsele/bem_candidato/bem_candidato_2022.zip';
    $bensTmp = sys_get_temp_dir() . '/tse_bens_2022.zip';
    $bensExt = sys_get_temp_dir() . '/tse_bens_2022';

    if (!is_dir($bensExt)) {
        echo "  Baixando bens de candidatos... ";
        if (!curlDownload($bensUrl, $bensTmp)) { echo "ERRO\n"; goto redes; }
        mkdir($bensExt, 0755, true);
        $za = new ZipArchive();
        $za->open($bensTmp);
        $za->extractTo($bensExt);
        $za->close();
        echo "OK\n";
    }

    // Mapa SQ → sapl_id (filtra pelos governadores da execução atual)
    $sqMap = [];
    foreach ($rows as $r) $sqMap[(string)$r['tse_sq']] = (int)$r['sapl_id'];

    $patrimonios = [];

    foreach (glob($bensExt . '/*.csv') as $csvFile) {
        $h = fopen($csvFile, 'r');
        fgetcsv($h, 0, ';'); // header
        while (($row = fgetcsv($h, 0, ';')) !== false) {
            $sq = trim($row[11] ?? '');
            if (!isset($sqMap[$sq])) continue;
            $val = trim($row[16] ?? '0');
            $patrimonios[$sqMap[$sq]] = ($patrimonios[$sqMap[$sq]] ?? 0.0) + parseBRL($val);
        }
        fclose($h);
    }

    $stPatr = $pdo->prepare(
        "UPDATE parl_perfil_detalhe SET patrimonio=? WHERE source_key='governadores' AND sapl_id=?"
    );

    $nomes = array_column($rows, 'nome_parlamentar', 'sapl_id');
    foreach ($patrimonios as $id => $total) {
        $stPatr->execute([$total, $id]);
        echo "  ✓ " . ($nomes[$id] ?? "id={$id}") . ": R$ " . number_format($total, 2, ',', '.') . "\n";
    }
    echo PHP_EOL;
}

// ── 3. Redes sociais ─────────────────────────────────────────────────────────
redes:
if (!$noRedes) {
    echo "=== Redes sociais ===\n";

    $stRede = $pdo->prepare(
        "INSERT INTO parl_redes_sociais (source_key, sapl_id, plataforma, url)
         VALUES ('governadores', ?, ?, ?)
         ON DUPLICATE KEY UPDATE url=VALUES(url)"
    );

    $byUf = [];
    foreach ($rows as $r) $byUf[$r['uf']][] = $r;

    foreach ($byUf as $uf => $govs) {
        $redeTmp = sys_get_temp_dir() . "/tse_redes_{$uf}.zip";
        $redeExt = sys_get_temp_dir() . "/tse_redes_{$uf}";
        $redeUrl = "https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/rede_social_candidato_2022_{$uf}.zip";

        if (!file_exists($redeTmp) || filesize($redeTmp) < 500) {
            if (!curlDownload($redeUrl, $redeTmp, 30)) { echo "  - {$uf}: erro ao baixar redes\n"; continue; }
        }

        if (!is_dir($redeExt)) {
            mkdir($redeExt, 0755, true);
            $za = new ZipArchive();
            $za->open($redeTmp);
            $za->extractTo($redeExt);
            $za->close();
        }

        $sqToId   = [];
        foreach ($govs as $g) $sqToId[(string)$g['tse_sq']] = (int)$g['sapl_id'];

        $csvFile = glob($redeExt . '/*.csv')[0] ?? null;
        if (!$csvFile) { echo "  - {$uf}: CSV de redes não encontrado\n"; continue; }

        $h = fopen($csvFile, 'r');
        fgetcsv($h, 0, ';'); // header
        $found = [];
        while (($row = fgetcsv($h, 0, ';')) !== false) {
            $sq  = trim($row[8] ?? '');
            $url = trim($row[10] ?? '');
            if (!isset($sqToId[$sq]) || !$url) continue;
            $id   = $sqToId[$sq];
            $plat = detectPlatform($url);
            $stRede->execute([$id, $plat, $url]);
            $found[$id][] = $plat;
        }
        fclose($h);

        foreach ($govs as $g) {
            $id = (int)$g['sapl_id'];
            $plats = $found[$id] ?? [];
            if ($plats) {
                echo "  ✓ {$g['nome_parlamentar']} ({$uf}): " . implode(', ', array_unique($plats)) . "\n";
            } else {
                echo "  - {$g['nome_parlamentar']} ({$uf}): sem redes cadastradas\n";
            }
        }

        usleep(200000);
    }
    echo PHP_EOL;
}

// ── 4. Mandato 2022: votos, coligação, resultado ─────────────────────────────
if (!$noMandato) {
    echo "=== Mandatos TSE (2018 e 2022) ===\n";

    $conv = fn(string $s): string => mb_convert_encoding(trim($s), 'UTF-8', 'ISO-8859-1');

    // Normaliza nome para matching fuzzy (sem acentos, uppercase)
    $normName = function(string $s): string {
        $s = mb_strtoupper(trim($s), 'UTF-8');
        $s = strtr($s, ['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E',
                         'Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C',
                         'Ñ'=>'N']);
        return preg_replace('/[^A-Z0-9 ]/', '', $s);
    };

    // Função que extrai dados de mandato de um consulta_cand ZIP por ano
    $processCandZip = function(int $ano, array $sqMap, array $nameUfMap) use ($conv, $normName, $pdo): array {
        $url    = "https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/consulta_cand_{$ano}.zip";
        $zipTmp = sys_get_temp_dir() . "/tse_consulta_cand_{$ano}.zip";
        $zipExt = sys_get_temp_dir() . "/tse_consulta_cand_{$ano}";

        if (!file_exists($zipTmp) || filesize($zipTmp) < 100000) {
            echo "  Baixando consulta_cand_{$ano}.zip... ";
            $fp = fopen($zipTmp, 'wb');
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_FILE=>$fp, CURLOPT_FOLLOWLOCATION=>true,
                CURLOPT_TIMEOUT=>90, CURLOPT_SSL_VERIFYPEER=>false,
                CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; keekconecta/1.0)']);
            curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fp);
            if ($code !== 200 || filesize($zipTmp) < 100000) { @unlink($zipTmp); echo "ERRO (HTTP $code)\n"; return []; }
            echo round(filesize($zipTmp)/1024) . "KB\n";
        }

        if (!is_dir($zipExt)) {
            mkdir($zipExt, 0755, true);
            $za = new ZipArchive(); $za->open($zipTmp); $za->extractTo($zipExt); $za->close();
        }

        $csvFile = $zipExt . "/consulta_cand_{$ano}_BRASIL.csv";
        if (!file_exists($csvFile)) $csvFile = glob($zipExt . '/*.csv')[0] ?? null;
        if (!$csvFile) { echo "  CSV {$ano} não encontrado\n"; return []; }

        $h = fopen($csvFile, 'r');
        $header = array_map('trim', str_getcsv(trim(fgets($h)), ';'));

        $iSq     = array_search('SQ_CANDIDATO',           $header);
        $iUrna   = array_search('NM_URNA_CANDIDATO',      $header);
        $iNome   = array_search('NM_CANDIDATO',           $header);
        $iUF     = array_search('SG_UF',                  $header);
        $iColig  = array_search('NM_COLIGACAO',            $header);
        $iComp   = array_search('DS_COMPOSICAO_COLIGACAO', $header);
        $iResult = array_search('DS_SIT_TOT_TURNO',        $header);
        $iTurno  = array_search('NR_TURNO',                $header);
        $iCargo  = array_search('DS_CARGO',                $header);

        if ($iSq === false) { fclose($h); echo "  SQ_CANDIDATO não encontrado em {$ano}\n"; return []; }

        $found = []; // sapl_id => [turno, coligacao, resultado]

        while (($row = fgetcsv($h, 0, ';')) !== false) {
            $cargo = $iCargo !== false ? strtoupper($conv($row[$iCargo] ?? '')) : '';
            if ($cargo && $cargo !== 'GOVERNADOR') continue;

            $sq    = trim($row[$iSq] ?? '');
            $uf    = $iUF !== false ? trim($row[$iUF] ?? '') : '';
            $turno = $iTurno !== false ? (int)trim($row[$iTurno] ?? '1') : 1;

            // Identifica o governador: por tse_sq (preciso) ou por nome+UF (fuzzy)
            $id = $sqMap[$sq] ?? null;
            if (!$id && $uf) {
                $urna     = mb_strtoupper($iUrna !== false ? $conv($row[$iUrna] ?? '') : '', 'UTF-8');
                $nomeCand = mb_strtoupper($iNome !== false ? $conv($row[$iNome] ?? '') : '', 'UTF-8');

                // 1. Exact match urna ou nome_candidato → nome_parlamentar normalizado
                $id = $nameUfMap[$uf . '|' . $urna] ?? $nameUfMap[$uf . '|' . $nomeCand] ?? null;

                if (!$id) {
                    foreach ($nameUfMap as $k => $sid) {
                        [$kuf, $knome] = explode('|', $k, 2);
                        if ($kuf !== $uf) continue;

                        // 2. Containment: nosso nome contém a urna, ou a urna contém nosso nome (min 4 chars)
                        $minLen = 4;
                        if (strlen($urna) >= $minLen && (str_contains($knome, $urna) || str_contains($urna, $knome))) {
                            $id = $sid; break;
                        }
                        // 3. Containment com nome completo TSE
                        if (strlen($nomeCand) >= $minLen && (str_contains($knome, $nomeCand) || str_contains($nomeCand, $knome))) {
                            $id = $sid; break;
                        }
                        // 4. Nosso nome curto contido na urna ou no nome TSE (ex: "ZEMA" em "ROMEU ZEMA")
                        if (strlen($knome) >= $minLen && (str_contains($urna, $knome) || str_contains($nomeCand, $knome))) {
                            $id = $sid; break;
                        }
                        // 5. similar_text > 80% como último recurso
                        similar_text($urna, $knome, $pct);
                        if ($pct > 80) { $id = $sid; break; }
                    }
                }
            }
            if (!$id) continue;

            $colig  = $iColig !== false ? $conv($row[$iColig] ?? '') : '';
            $comp   = $iComp  !== false ? $conv($row[$iComp]  ?? '') : '';
            $result = $iResult !== false ? $conv($row[$iResult] ?? '') : '';

            if ($colig === 'PARTIDO ISOLADO' && $comp) $colig = $comp;
            if ($colig === '#NULO') $colig = '';
            if ($result === '#NULO') $result = '';

            // Prioriza turno 2
            if (!isset($found[$id]) || $turno > ($found[$id]['turno'] ?? 0)) {
                $found[$id] = ['turno'=>$turno, 'coligacao'=>$colig, 'resultado'=>$result];
            }
        }
        fclose($h);
        return $found;
    };

    // Mapas de lookup
    $sqMap     = [];  // tse_sq → sapl_id
    $nameUfMap = [];  // "UF|NOME_URNA" → sapl_id
    $nomes     = array_column($rows, 'nome_parlamentar', 'sapl_id');

    foreach ($rows as $r) {
        if ($r['tse_sq']) $sqMap[(string)$r['tse_sq']] = (int)$r['sapl_id'];
        // Para matching por nome: usamos o nome_parlamentar como aproximação do nome de urna
        $nameUfMap[$r['uf'] . '|' . mb_strtoupper(trim($r['nome_parlamentar']), 'UTF-8')] = (int)$r['sapl_id'];
    }

    $stUpsert = $pdo->prepare(
        "INSERT INTO parl_mandatos_gov (source_key, sapl_id, ano_eleicao, periodo_ini, periodo_fim, turno, coligacao, resultado)
         VALUES ('governadores', ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE turno=VALUES(turno), coligacao=VALUES(coligacao), resultado=VALUES(resultado)"
    );

    // 2022 → mandato 2023-2026; 2018 → mandato 2019-2022
    foreach ([2022 => [2023,2026], 2018 => [2019,2022]] as $ano => [$ini, $fim]) {
        echo "  Processando {$ano}...\n";
        $data = $processCandZip($ano, $sqMap, $nameUfMap);
        foreach ($data as $id => $m) {
            $stUpsert->execute([$id, $ano, $ini, $fim, $m['turno'], $m['coligacao'], $m['resultado']]);
            $res = $m['resultado'] ?: '—';
            echo "    ✓ " . ($nomes[$id] ?? "id={$id}") . ": {$res} T{$m['turno']} | {$m['coligacao']}\n";
        }
        echo PHP_EOL;
    }

    // Mantém retrocompatibilidade: atualiza colunas legadas em parl_perfil_detalhe
    $stLeg = $pdo->prepare(
        "UPDATE parl_perfil_detalhe pd
         JOIN parl_mandatos_gov mg
           ON mg.source_key COLLATE utf8mb4_unicode_ci = pd.source_key COLLATE utf8mb4_unicode_ci
          AND mg.sapl_id = pd.sapl_id
          AND mg.ano_eleicao = 2022
         SET pd.coligacao_2022=mg.coligacao, pd.resultado_2022=mg.resultado, pd.turno_2022=mg.turno
         WHERE pd.source_key='governadores'"
    );
    $stLeg->execute();
}

// ── 5. Votos (2022: TSE JSON API; 2018: munzona por UF) ───────────────────────
if (!$noVotos) {
    echo "=== Votos por mandato ===\n";

    $conv5     = fn(string $s): string => mb_convert_encoding(trim($s), 'UTF-8', 'ISO-8859-1');
    $normName5 = function(string $s): string {
        $s = mb_strtoupper(trim($s), 'UTF-8');
        $s = strtr($s, ['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E',
                         'Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C','Ñ'=>'N']);
        return preg_replace('/[^A-Z0-9 ]/', '', $s);
    };

    $stVotos = $pdo->prepare(
        "UPDATE parl_mandatos_gov SET votos=?, pct_votos=?
         WHERE source_key='governadores' AND sapl_id=? AND ano_eleicao=?"
    );

    // Turno decisivo por governador/ano (já salvo na fase 4)
    $turnoMap5 = [];
    foreach ($pdo->query(
        "SELECT sapl_id, ano_eleicao, turno FROM parl_mandatos_gov WHERE source_key='governadores'"
    )->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $turnoMap5[(int)$m['sapl_id']][(int)$m['ano_eleicao']] = (int)($m['turno'] ?? 1);
    }

    // ── 2022: TSE JSON API (~3KB por UF, sem download pesado) ─────────────────
    echo "  2022 via JSON API...\n";

    $roundCands = []; // [uf][turno][sq] => ['vap'=>int, 'pvap'=>float|null]
    foreach (array_unique(array_column($rows, 'uf')) as $uf) {
        $ufLow = strtolower($uf);
        foreach ([546 => 1, 547 => 2] as $code => $turno) {
            $fcode = sprintf('%06d', $code);
            $url   = "https://resultados.tse.jus.br/oficial/ele2022/{$code}/dados-simplificados/{$ufLow}/{$ufLow}-c0003-e{$fcode}-r.json";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
            ]);
            $body = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http !== 200 || !$body) continue;
            $json = json_decode($body, true);
            if (empty($json['cand'])) continue;
            foreach ($json['cand'] as $c) {
                $sq  = (string)($c['sqcand'] ?? '');
                $vap = (int)str_replace('.', '', (string)($c['vap'] ?? 0));
                $pvap = isset($c['pvap']) ? (float)str_replace(',', '.', (string)$c['pvap']) : null;
                $roundCands[$uf][$turno][$sq] = ['vap' => $vap, 'pvap' => $pvap];
            }
        }
    }

    foreach ($rows as $r) {
        $id    = (int)$r['sapl_id'];
        $sq    = (string)($r['tse_sq'] ?? '');
        $uf    = $r['uf'];
        if (!isset($turnoMap5[$id][2022])) continue;
        $turno = $turnoMap5[$id][2022];

        $cands = $roundCands[$uf][$turno] ?? [];
        if (!$sq || !isset($cands[$sq])) {
            echo "    - {$r['nome_parlamentar']} ({$uf}): sq={$sq} não encontrado (T{$turno})\n";
            continue;
        }
        $vap  = $cands[$sq]['vap'];
        $pvap = $cands[$sq]['pvap'];
        $stVotos->execute([$vap, $pvap, $id, 2022]);
        $pctStr = $pvap !== null ? number_format($pvap, 2, ',', '.') . '%' : '?%';
        echo "    ✓ {$r['nome_parlamentar']} ({$uf}): " . number_format($vap, 0, ',', '.') . " votos ({$pctStr}) T{$turno}\n";
    }
    echo PHP_EOL;

    // ── 2018: votacao_candidato_munzona_2018.zip (arquivo nacional) ───────────
    echo "  2018 via munzona nacional...\n";

    // Quais governadores têm mandato 2018?
    $have2018 = array_keys(array_filter($turnoMap5, fn($anos) => isset($anos[2018])));
    if (empty($have2018)) {
        echo "    Nenhum governador com mandato 2018.\n";
    } else {
        // Mapa: [uf][nome_norm] => sapl_id  (só para os que têm mandato 2018)
        $nameMap2018 = [];
        $ufNeed2018  = [];
        foreach ($rows as $r) {
            if (!in_array((int)$r['sapl_id'], $have2018)) continue;
            $norm = $normName5($r['nome_parlamentar']);
            $nameMap2018[$r['uf']][$norm] = (int)$r['sapl_id'];
            $ufNeed2018[$r['uf']] = true;
        }

        $munzTmp = sys_get_temp_dir() . '/tse_munzona_2018.zip';
        $munzExt = sys_get_temp_dir() . '/tse_munzona_2018';
        $munzUrl = 'https://cdn.tse.jus.br/estatistica/sead/odsele/votacao_candidato_munzona/votacao_candidato_munzona_2018.zip';

        if (!file_exists($munzTmp) || filesize($munzTmp) < 1000000) {
            echo "    Baixando munzona_2018.zip (~395MB, aguarde)... ";
            flush();
            if (!curlDownload($munzUrl, $munzTmp, 600)) { echo "ERRO\n"; goto fim; }
            echo round(filesize($munzTmp) / 1024 / 1024, 1) . "MB\n";
        }

        if (!is_dir($munzExt)) {
            echo "    Extraindo... ";
            flush();
            mkdir($munzExt, 0755, true);
            $za = new ZipArchive();
            $za->open($munzTmp);
            $za->extractTo($munzExt);
            $za->close();
            echo "OK\n";
        }

        // O ZIP contém um CSV por UF (e BR/BRASIL agregados que ignoramos)
        $votosCand = []; // [sq][turno] => int
        $totalUfT  = []; // [uf][turno] => int
        $sqInfo    = []; // [sq] => ['uf'=>, 'sapl_id'=>]

        foreach (glob($munzExt . '/*.csv') as $csvFile) {
            // Extrai UF do nome: votacao_..._2018_AC.csv → AC  (ignora BRASIL, BR, etc.)
            if (!preg_match('/\d{4}_([A-Z]{2})\.csv$/i', basename($csvFile), $m)) continue;
            $fileUf = strtoupper($m[1]);
            if (!isset($ufNeed2018[$fileUf])) continue;

            echo "    Processando {$fileUf}...\n"; flush();

            $h = fopen($csvFile, 'r');
            $header = array_map(fn($c) => trim($c, '"'), array_map('trim', str_getcsv(trim(fgets($h)), ';')));

            $iCargo = array_search('DS_CARGO',          $header);
            $iSq    = array_search('SQ_CANDIDATO',      $header);
            $iNome  = array_search('NM_CANDIDATO',      $header);
            $iUrna  = array_search('NM_URNA_CANDIDATO', $header);
            $iTurno = array_search('NR_TURNO',          $header);
            $iVotos = array_search('QT_VOTOS_NOMINAIS', $header);

            if ($iSq === false || $iVotos === false) {
                echo "    - {$fileUf}: colunas não encontradas\n"; fclose($h); continue;
            }

            $byUf = $nameMap2018[$fileUf] ?? [];

            while (($row = fgetcsv($h, 0, ';')) !== false) {
                if ($iCargo !== false) {
                    $cargo = strtoupper($conv5($row[$iCargo] ?? ''));
                    if ($cargo !== 'GOVERNADOR') continue;
                }
                $sq    = trim($row[$iSq] ?? '');
                $turno = (int)trim($row[$iTurno !== false ? $iTurno : 0] ?? '1');
                $votos = (int)trim($row[$iVotos] ?? '0');
                if (!$sq || $votos <= 0) continue;

                $votosCand[$sq][$turno]        = ($votosCand[$sq][$turno] ?? 0) + $votos;
                $totalUfT[$fileUf][$turno]     = ($totalUfT[$fileUf][$turno] ?? 0) + $votos;

                if (!isset($sqInfo[$sq]) && !empty($byUf)) {
                    $urna     = $normName5($iUrna !== false ? $conv5($row[$iUrna] ?? '') : '');
                    $nomeCand = $normName5($iNome !== false ? $conv5($row[$iNome] ?? '') : '');
                    foreach ($byUf as $knome => $sid) {
                        if ($urna === $knome || $nomeCand === $knome) { $sqInfo[$sq] = ['uf'=>$fileUf,'sapl_id'=>$sid]; break; }
                        if (strlen($urna) >= 4 && (str_contains($knome, $urna) || str_contains($urna, $knome))) { $sqInfo[$sq] = ['uf'=>$fileUf,'sapl_id'=>$sid]; break; }
                        if (strlen($nomeCand) >= 4 && (str_contains($knome, $nomeCand) || str_contains($nomeCand, $knome))) { $sqInfo[$sq] = ['uf'=>$fileUf,'sapl_id'=>$sid]; break; }
                        if (strlen($knome) >= 4 && (str_contains($urna, $knome) || str_contains($nomeCand, $knome))) { $sqInfo[$sq] = ['uf'=>$fileUf,'sapl_id'=>$sid]; break; }
                    }
                }
            }
            fclose($h);
        }

        // Aplica ao banco
        foreach ($have2018 as $id) {
            $govRow = null;
            foreach ($rows as $r) { if ((int)$r['sapl_id'] === $id) { $govRow = $r; break; } }
            if (!$govRow) continue;

            $turno = $turnoMap5[$id][2018] ?? 1;
            $uf    = $govRow['uf'];
            $sq    = null;
            foreach ($sqInfo as $s => $info) {
                if ($info['sapl_id'] === $id) { $sq = $s; break; }
            }

            if (!$sq || !isset($votosCand[$sq][$turno])) {
                echo "    - {$govRow['nome_parlamentar']} ({$uf}): sq 2018 não encontrado (T{$turno})\n";
                continue;
            }

            $vap   = $votosCand[$sq][$turno];
            $total = $totalUfT[$uf][$turno] ?? 0;
            $pvap  = $total > 0 ? round($vap / $total * 100, 2) : null;

            $stVotos->execute([$vap, $pvap, $id, 2018]);
            $pctStr = $pvap !== null ? number_format($pvap, 2, ',', '.') . '%' : '?%';
            echo "    ✓ {$govRow['nome_parlamentar']} ({$uf}): " . number_format($vap, 0, ',', '.') . " votos ({$pctStr}) T{$turno}\n";
        }
    }
    echo PHP_EOL;
}

fim:
echo "[gov-extras] Concluído.\n";
