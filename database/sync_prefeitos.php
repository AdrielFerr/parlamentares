<?php
/**
 * sync_prefeitos.php
 *
 * Importa prefeitos eleitos em 2024 das RMs cadastradas em municipios_rm.
 *
 *   php database/sync_prefeitos.php              — tudo
 *   php database/sync_prefeitos.php --no-fotos   — pula fotos
 *   php database/sync_prefeitos.php --no-bens    — pula patrimônio
 *   php database/sync_prefeitos.php --no-redes   — pula redes sociais
 *   php database/sync_prefeitos.php --no-votos   — pula votos
 *   php database/sync_prefeitos.php RJ PE        — só esses estados
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$args     = array_slice($argv, 1);
$noFotos  = in_array('--no-fotos',  $args);
$noBens   = in_array('--no-bens',   $args);
$noRedes  = in_array('--no-redes',  $args);
$noVotos  = in_array('--no-votos',  $args);
$ufsArg   = array_values(array_filter($args, fn($a) => strlen($a) === 2 && ctype_alpha($a)));

$pdo       = Database::connect();
$uploadDir = ROOT . '/public/uploads/parlamentares/prefeitos';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// Municípios das RMs que queremos
$sqlMun = "SELECT cd_municipio, nm_municipio, uf, cd_rm, nm_rm FROM municipios_rm";
$params = [];
if ($ufsArg) {
    $in = implode(',', array_fill(0, count($ufsArg), '?'));
    $sqlMun .= " WHERE uf IN ({$in})";
    $params = $ufsArg;
}
$munRows = $pdo->prepare($sqlMun);
$munRows->execute($params);
$munRows = $munRows->fetchAll(PDO::FETCH_ASSOC);
echo "[prefeitos] " . count($munRows) . " municípios nas RMs\n\n";

if (!$munRows) { echo "Nenhum município encontrado. Execute migrate_prefeitos.php primeiro.\n"; exit(1); }

// ── Helpers ──────────────────────────────────────────────────────────────────

function curlGet(string $url, int $timeout = 20): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $body) ? $body : null;
}

function curlDownload(string $url, string $dest, int $timeout = 300): bool {
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
    return (float)str_replace(',', '.', str_replace('.', '', trim($val)));
}

$conv = fn(string $s): string => mb_convert_encoding(trim($s), 'UTF-8', 'ISO-8859-1');

$normName = function(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = strtr($s, ['Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E',
                     'Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C','Ñ'=>'N']);
    return preg_replace('/[^A-Z0-9 ]/', '', $s);
};

// ── Mapas de lookup para municípios ──────────────────────────────────────────

// [uf][nome_norm] => cd_municipio
$munByName = [];
// cd_municipio => row completo
$munById   = [];

foreach ($munRows as $m) {
    $munById[(int)$m['cd_municipio']] = $m;
    $munByName[$m['uf']][$normName($m['nm_municipio'])] = (int)$m['cd_municipio'];
}

// Prefeitos já importados: tse_sq → sapl_id (para fases de enriquecimento)
$sqToSapl = [];  // tse_sq => sapl_id (preenchido na fase 1)

// ── 1. Candidatura 2024 ──────────────────────────────────────────────────────
echo "=== Fase 1: Candidatura 2024 ===\n";

$candUrl = 'https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/consulta_cand_2024.zip';
$candTmp = sys_get_temp_dir() . '/tse_consulta_cand_2024.zip';
$candExt = sys_get_temp_dir() . '/tse_consulta_cand_2024';

if (!file_exists($candTmp) || filesize($candTmp) < 55000000) {
    echo "  Baixando consulta_cand_2024.zip (~61MB, pode demorar)... ";
    flush();
    if (!curlDownload($candUrl, $candTmp, 600)) { echo "ERRO\n"; exit(1); }
    echo round(filesize($candTmp) / 1024 / 1024, 1) . "MB\n";
}

// Extrai apenas os CSVs das UFs necessárias (evita extrair BRASIL.csv de 231MB e demais)
$ufsProcessar = $ufsArg ?: ['RJ', 'PB', 'PE'];
if (!is_dir($candExt)) mkdir($candExt, 0755, true);

$za = new ZipArchive();
if ($za->open($candTmp) !== true) { echo "ERRO ao abrir ZIP\n"; exit(1); }
foreach ($ufsProcessar as $ufProc) {
    $entry = "consulta_cand_2024_{$ufProc}.csv";
    $dest  = $candExt . '/' . $entry;
    if (!file_exists($dest)) {
        $data = $za->getFromName($entry);
        if ($data === false) { echo "  AVISO: {$entry} não encontrado no ZIP\n"; continue; }
        file_put_contents($dest, $data);
        echo "  Extraído {$entry} (" . round(strlen($data)/1024/1024, 1) . "MB)\n";
    }
}
$za->close();

// [cd_municipio] => melhor linha (prioriza turno 2)
$found = [];

foreach ($ufsProcessar as $ufProc) {
    $csvCand = $candExt . "/consulta_cand_2024_{$ufProc}.csv";
    if (!file_exists($csvCand)) { echo "  CSV {$ufProc} não encontrado\n"; continue; }

    $h = fopen($csvCand, 'r');
    $header = array_map(fn($c) => trim($c, '"'), array_map('trim', str_getcsv(trim(fgets($h)), ';')));

    $iSq     = array_search('SQ_CANDIDATO',          $header);
    $iUrna   = array_search('NM_URNA_CANDIDATO',      $header);
    $iNome   = array_search('NM_CANDIDATO',           $header);
    $iUF     = array_search('SG_UF',                  $header);
    $iMunNm  = array_search('NM_UE',                  $header);
    $iMunCd  = array_search('CD_MUNICIPIO',           $header);
    $iCargo  = array_search('DS_CARGO',               $header);
    $iColig  = array_search('NM_COLIGACAO',            $header);
    $iComp   = array_search('DS_COMPOSICAO_COLIGACAO', $header);
    $iResult = array_search('DS_SIT_TOT_TURNO',        $header);
    $iTurno  = array_search('NR_TURNO',                $header);

    echo "  Processando {$ufProc} (SQ={$iSq} MunCd={$iMunCd} MunNm={$iMunNm} Cargo={$iCargo})\n";

    while (($row = fgetcsv($h, 0, ';')) !== false) {
        $cargo = $iCargo !== false ? strtoupper($conv($row[$iCargo] ?? '')) : '';
        if ($cargo !== 'PREFEITO') continue;

        $uf = $iUF !== false ? strtoupper(trim($row[$iUF] ?? '')) : $ufProc;

        // Resolve cd_municipio: por código direto ou por nome
        $cdMun = null;
        if ($iMunCd !== false && is_numeric(trim($row[$iMunCd] ?? ''))) {
            $cdMun = (int)trim($row[$iMunCd]);
        }
        if (!$cdMun || !isset($munById[$cdMun])) {
            $nmUe  = $iMunNm !== false ? $normName($conv($row[$iMunNm] ?? '')) : '';
            $cdMun = $munByName[$uf][$nmUe] ?? null;
        }
        if (!$cdMun) continue;

        $turno  = $iTurno !== false ? (int)trim($row[$iTurno] ?? '1') : 1;
        $result = $iResult !== false ? $conv($row[$iResult] ?? '') : '';
        if (!str_contains(strtoupper($result), 'ELEITO')) continue;

        // Prioriza turno 2
        if (!isset($found[$cdMun]) || $turno > ($found[$cdMun]['turno'] ?? 0)) {
            $found[$cdMun] = [
                'sq'        => trim($row[$iSq] ?? ''),
                'nome'      => $iNome  !== false ? $conv($row[$iNome]  ?? '') : '',
                'urna'      => $iUrna  !== false ? $conv($row[$iUrna]  ?? '') : '',
                'uf'        => $uf,
                'turno'     => $turno,
                'coligacao' => $iColig !== false ? $conv($row[$iColig] ?? '') : '',
                'comp'      => $iComp  !== false ? $conv($row[$iComp]  ?? '') : '',
                'resultado' => $result,
                'cdMun'     => $cdMun,
            ];
        }
    }
    fclose($h);
}

$stParl = $pdo->prepare(
    "INSERT INTO parl_parlamentares
       (source_key, sapl_id, uf, nome_parlamentar, tse_sq, cd_municipio, nm_municipio, nm_rm)
     VALUES ('prefeitos', ?, ?, ?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       nome_parlamentar=VALUES(nome_parlamentar),
       tse_sq=VALUES(tse_sq),
       uf=VALUES(uf),
       cd_municipio=VALUES(cd_municipio),
       nm_municipio=VALUES(nm_municipio),
       nm_rm=VALUES(nm_rm)"
);

$stDetalhe = $pdo->prepare(
    "INSERT IGNORE INTO parl_perfil_detalhe (source_key, sapl_id) VALUES ('prefeitos', ?)"
);

$stMandato = $pdo->prepare(
    "INSERT INTO parl_mandatos_pref
       (source_key, sapl_id, ano_eleicao, periodo_ini, periodo_fim, turno, coligacao, resultado)
     VALUES ('prefeitos', ?, 2024, 2025, 2028, ?, ?, ?)
     ON DUPLICATE KEY UPDATE
       turno=VALUES(turno), coligacao=VALUES(coligacao), resultado=VALUES(resultado)"
);

foreach ($found as $cdMun => $f) {
    $munInfo = $munById[$cdMun] ?? null;
    if (!$munInfo) continue;

    $colig = $f['coligacao'];
    if (($colig === 'PARTIDO ISOLADO' || $colig === '') && $f['comp']) $colig = $f['comp'];
    if ($colig === '#NULO') $colig = '';

    $sapl = $cdMun; // usamos cd_municipio como sapl_id

    $stParl->execute([
        $sapl,
        $f['uf'],
        $f['urna'] ?: $f['nome'],
        $f['sq'],
        $cdMun,
        $munInfo['nm_municipio'],
        $munInfo['nm_rm'],
    ]);
    $stDetalhe->execute([$sapl]);
    $stMandato->execute([$sapl, $f['turno'] ?: null, $colig ?: null, $f['resultado'] ?: null]);

    $sqToSapl[$f['sq']] = $sapl;
    echo "  ✓ {$f['urna']} — {$munInfo['nm_municipio']} ({$f['uf']}) T{$f['turno']}\n";
}

echo "  Total importados: " . count($found) . "\n\n";

// ── 2. Fotos ─────────────────────────────────────────────────────────────────
if (!$noFotos) {
    echo "=== Fase 2: Fotos ===\n";

    $stFoto = $pdo->prepare(
        "UPDATE parl_parlamentares SET fotografia_url=? WHERE source_key='prefeitos' AND sapl_id=?"
    );

    // Agrupa por UF
    $sqByUf = [];
    foreach ($found as $cdMun => $f) {
        if ($f['sq']) $sqByUf[$f['uf']][$f['sq']] = $cdMun;
    }

    foreach ($sqByUf as $uf => $sqMap) {
        $zipTmp = sys_get_temp_dir() . "/tse_fotos2024_{$uf}.zip";
        $zipUrl = "https://cdn.tse.jus.br/estatistica/sead/eleicoes/eleicoes2024/fotos/foto_cand2024_{$uf}_div.zip";

        if (!file_exists($zipTmp) || filesize($zipTmp) < 10000) {
            echo "  Baixando fotos {$uf}... ";
            if (!curlDownload($zipUrl, $zipTmp, 600)) { echo "ERRO\n"; continue; }
            echo round(filesize($zipTmp) / 1024 / 1024, 1) . "MB\n";
        }

        $za = new ZipArchive();
        if ($za->open($zipTmp) !== true) { echo "  - {$uf}: falha ao abrir ZIP\n"; continue; }

        foreach ($sqMap as $sq => $cdMun) {
            $content = $za->getFromName("F{$uf}{$sq}_div.jpg") ?: $za->getFromName("F{$uf}{$sq}_div.jpeg");
            if ($content === false || strlen($content) < 5000) {
                echo "  - {$munById[$cdMun]['nm_municipio']} ({$uf}): foto não encontrada\n";
                continue;
            }
            $dest = $uploadDir . '/' . $cdMun . '.jpg';
            file_put_contents($dest, $content);
            $url = '/uploads/parlamentares/prefeitos/' . $cdMun . '.jpg';
            $stFoto->execute([$url, $cdMun]);
            echo "  ✓ {$munById[$cdMun]['nm_municipio']}: " . round(strlen($content) / 1024) . "KB\n";
        }
        $za->close();
    }
    echo PHP_EOL;
}

// ── 3. Patrimônio ────────────────────────────────────────────────────────────
if (!$noBens) {
    echo "=== Fase 3: Patrimônio ===\n";

    $bensUrl = 'https://cdn.tse.jus.br/estatistica/sead/odsele/bem_candidato/bem_candidato_2024.zip';
    $bensTmp = sys_get_temp_dir() . '/tse_bens_2024.zip';
    $bensExt = sys_get_temp_dir() . '/tse_bens_2024';

    if (!file_exists($bensTmp) || filesize($bensTmp) < 40000000) {
        echo "  Baixando bens 2024 (~44MB)... ";
        if (!curlDownload($bensUrl, $bensTmp, 400)) { echo "ERRO\n"; goto redes; }
        echo round(filesize($bensTmp) / 1024 / 1024, 1) . "MB\n";
    }
    if (!is_dir($bensExt)) {
        mkdir($bensExt, 0755, true);
        $za = new ZipArchive();
        if ($za->open($bensTmp) !== true) { echo "  ERRO ao abrir ZIP bens\n"; goto redes; }
        $za->extractTo($bensExt); $za->close();
    }

    $patrimonios = []; // sapl_id => total
    foreach (glob($bensExt . '/*.csv') as $csvFile) {
        $h = fopen($csvFile, 'r');
        $hdr = array_map(fn($c) => trim($c, '"'), array_map('trim', str_getcsv(trim(fgets($h)), ';')));
        $iSqB = array_search('SQ_CANDIDATO', $hdr);
        $iValB = array_search('VR_BEM_CANDIDATO', $hdr) !== false
               ? array_search('VR_BEM_CANDIDATO', $hdr)
               : array_search('VL_BEM_CANDIDATO', $hdr);
        if ($iSqB === false || $iValB === false) { fclose($h); continue; }
        while (($row = fgetcsv($h, 0, ';')) !== false) {
            $sq  = trim($row[$iSqB] ?? '');
            $sid = $sqToSapl[$sq] ?? null;
            if (!$sid) continue;
            $patrimonios[$sid] = ($patrimonios[$sid] ?? 0.0) + parseBRL($conv($row[$iValB] ?? '0'));
        }
        fclose($h);
    }

    $stPatr = $pdo->prepare(
        "UPDATE parl_perfil_detalhe SET patrimonio=? WHERE source_key='prefeitos' AND sapl_id=?"
    );
    foreach ($patrimonios as $id => $total) {
        $stPatr->execute([$total, $id]);
        $nome = $found[$id]['urna'] ?? "id={$id}";
        echo "  ✓ " . ($munById[$id]['nm_municipio'] ?? "id={$id}") . ": R$ " . number_format($total, 2, ',', '.') . "\n";
    }
    echo PHP_EOL;
}

// ── 4. Redes sociais ─────────────────────────────────────────────────────────
redes:
if (!$noRedes) {
    echo "=== Fase 4: Redes sociais ===\n";

    $redesUrl = 'https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/rede_social_candidato_2024.zip';
    $redesTmp = sys_get_temp_dir() . '/tse_redes_2024.zip';
    $redesExt = sys_get_temp_dir() . '/tse_redes_2024';

    if (!file_exists($redesTmp) || filesize($redesTmp) < 18000000) {
        echo "  Baixando redes 2024 (~19MB)... ";
        if (!curlDownload($redesUrl, $redesTmp, 200)) { echo "ERRO\n"; goto votos; }
        echo round(filesize($redesTmp) / 1024 / 1024, 1) . "MB\n";
    }
    if (!is_dir($redesExt)) {
        mkdir($redesExt, 0755, true);
        $za = new ZipArchive();
        if ($za->open($redesTmp) !== true) { echo "  ERRO ao abrir ZIP redes\n"; goto votos; }
        $za->extractTo($redesExt); $za->close();
    }

    $csvRede = glob($redesExt . '/*BRASIL*.csv')[0] ?? glob($redesExt . '/*.csv')[0] ?? null;
    if (!$csvRede) { echo "  CSV redes não encontrado\n"; goto votos; }

    $detectPlat = fn(string $url): string => match(true) {
        str_contains($url, 'instagram') => 'instagram',
        str_contains($url, 'facebook')  => 'facebook',
        str_contains($url, 'twitter')   => 'twitter',
        str_contains($url, 'x.com')     => 'twitter',
        str_contains($url, 'youtube')   => 'youtube',
        str_contains($url, 'tiktok')    => 'tiktok',
        str_contains($url, 'linkedin')  => 'linkedin',
        default                         => 'outro',
    };

    $stRede = $pdo->prepare(
        "INSERT INTO parl_redes_sociais (source_key, sapl_id, plataforma, url)
         VALUES ('prefeitos', ?, ?, ?)
         ON DUPLICATE KEY UPDATE url=VALUES(url)"
    );

    $h = fopen($csvRede, 'r');
    $hdr = array_map(fn($c) => trim($c, '"'), array_map('trim', str_getcsv(trim(fgets($h)), ';')));
    $iSqR  = array_search('SQ_CANDIDATO', $hdr);
    $iUrlR = array_search('DS_URL',       $hdr);
    if ($iSqR === false || $iUrlR === false) { fclose($h); echo "  Colunas de redes não encontradas\n"; goto votos; }

    $redeCounts = [];
    while (($row = fgetcsv($h, 0, ';')) !== false) {
        $sq  = trim($row[$iSqR] ?? '');
        $sid = $sqToSapl[$sq] ?? null;
        if (!$sid) continue;
        $url = trim($conv($row[$iUrlR] ?? ''));
        if (!$url || $url === '#NULO') continue;
        $plat = $detectPlat(strtolower($url));
        $stRede->execute([$sid, $plat, $url]);
        $redeCounts[$sid][] = $plat;
    }
    fclose($h);

    foreach ($redeCounts as $sid => $plats) {
        echo "  ✓ " . ($munById[$sid]['nm_municipio'] ?? "id={$sid}") . ": " . implode(', ', array_unique($plats)) . "\n";
    }
    echo PHP_EOL;
}

// ── 5. Votos ─────────────────────────────────────────────────────────────────
votos:
if (!$noVotos) {
    echo "=== Fase 5: Votos ===\n";

    $munzUrl = 'https://cdn.tse.jus.br/estatistica/sead/odsele/votacao_candidato_munzona/votacao_candidato_munzona_2024.zip';
    $munzTmp = sys_get_temp_dir() . '/tse_munzona_2024.zip';
    $munzExt = sys_get_temp_dir() . '/tse_munzona_2024';

    if (!file_exists($munzTmp) || filesize($munzTmp) < 48000000) {
        echo "  Baixando munzona_2024.zip (~50MB)... ";
        if (!curlDownload($munzUrl, $munzTmp, 400)) { echo "ERRO\n"; goto fim; }
        echo round(filesize($munzTmp) / 1024 / 1024, 1) . "MB\n";
    }
    if (!is_dir($munzExt)) {
        echo "  Extraindo... "; flush();
        mkdir($munzExt, 0755, true);
        $za = new ZipArchive();
        if ($za->open($munzTmp) !== true) { echo "ERRO ao abrir ZIP munzona\n"; goto fim; }
        $za->extractTo($munzExt); $za->close();
        echo "OK\n";
    }

    // Turno decisivo por sapl_id (prefeitos já importados)
    $turnoMap = [];
    foreach ($pdo->query(
        "SELECT sapl_id, turno FROM parl_mandatos_pref WHERE source_key='prefeitos'"
    )->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $turnoMap[(int)$m['sapl_id']] = (int)($m['turno'] ?? 1);
    }

    // Mapa sq → cd_municipio (IBGE) para candidatos nossos
    $sqToMun = [];
    foreach ($found as $cdMun => $f) {
        if ($f['sq']) $sqToMun[$f['sq']] = $cdMun;
    }

    $votosCand  = []; // [sq][turno] => int
    $totalTSEMun = []; // [tse_cd_municipio][turno] => int (todos cands ao cargo)
    $sqToTseMun  = []; // sq (nossos) => tse_cd_municipio

    foreach (glob($munzExt . '/*.csv') as $csvFile) {
        $h = fopen($csvFile, 'r');
        $hdr = array_map(fn($c) => trim($c, '"'), array_map('trim', str_getcsv(trim(fgets($h)), ';')));

        $iCargo = array_search('DS_CARGO',          $hdr);
        $iSq    = array_search('SQ_CANDIDATO',      $hdr);
        $iMunCd = array_search('CD_MUNICIPIO',      $hdr);
        $iTurno = array_search('NR_TURNO',          $hdr);
        $iVotos = array_search('QT_VOTOS_NOMINAIS', $hdr);

        if ($iSq === false || $iVotos === false) { fclose($h); continue; }
        echo "  Processando " . basename($csvFile) . "...\n"; flush();

        while (($row = fgetcsv($h, 0, ';')) !== false) {
            if ($iCargo !== false) {
                $cargo = strtoupper($conv($row[$iCargo] ?? ''));
                if ($cargo !== 'PREFEITO') continue;
            }
            $sq     = trim($row[$iSq] ?? '');
            $turno  = (int)trim($row[$iTurno !== false ? $iTurno : 0] ?? '1');
            $votos  = (int)trim($row[$iVotos] ?? '0');
            $tseMun = $iMunCd !== false ? (int)trim($row[$iMunCd] ?? '0') : 0;
            if (!$sq || $votos <= 0) continue;

            // Acumula votos do candidato
            if (isset($sqToMun[$sq])) {
                $votosCand[$sq][$turno] = ($votosCand[$sq][$turno] ?? 0) + $votos;
                if ($tseMun && !isset($sqToTseMun[$sq])) $sqToTseMun[$sq] = $tseMun;
            }
            // Acumula total do município (para % )
            if ($tseMun) {
                $totalTSEMun[$tseMun][$turno] = ($totalTSEMun[$tseMun][$turno] ?? 0) + $votos;
            }
        }
        fclose($h);
    }

    $stVotos = $pdo->prepare(
        "UPDATE parl_mandatos_pref SET votos=?, pct_votos=?
         WHERE source_key='prefeitos' AND sapl_id=? AND ano_eleicao=2024"
    );

    foreach ($found as $cdMun => $f) {
        $sapl  = $cdMun;
        $sq    = $f['sq'];
        $turno = $turnoMap[$sapl] ?? ($f['turno'] ?: 1);

        if (!$sq || !isset($votosCand[$sq][$turno])) {
            echo "  - " . ($munById[$cdMun]['nm_municipio'] ?? $cdMun) . ": votos não encontrados (T{$turno})\n";
            continue;
        }

        $vap    = $votosCand[$sq][$turno];
        $tseMun = $sqToTseMun[$sq] ?? 0;
        $total  = $tseMun ? ($totalTSEMun[$tseMun][$turno] ?? 0) : 0;
        $pvap   = $total > 0 ? round($vap / $total * 100, 2) : null;

        $stVotos->execute([$vap, $pvap, $sapl]);
        $pctStr = $pvap !== null ? number_format($pvap, 2, ',', '.') . '%' : '?%';
        echo "  ✓ " . ($munById[$cdMun]['nm_municipio'] ?? $cdMun) . " ({$f['uf']}): " . number_format($vap, 0, ',', '.') . " ({$pctStr}) T{$turno}\n";
    }
    echo PHP_EOL;
}

fim:
echo "[prefeitos] Concluído.\n";
