<?php
/**
 * sync_governadores.php
 *
 * Importa governadores eleitos do CSV do TSE (2022) e fotos do Wikidata.
 * Armazena em parl_parlamentares com source_key='governadores'.
 *
 * Uso:
 *   php database/sync_governadores.php              — todos os estados
 *   php database/sync_governadores.php PE MG RJ     — estados específicos
 *   php database/sync_governadores.php --force      — rebaixa fotos existentes
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Acesso negado.'); }

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$args   = array_slice($argv, 1);
$force  = in_array('--force', $args);
$ufsArg = array_values(array_filter($args, fn($a) => $a !== '--force'));

const SOURCE  = 'governadores';
const CSV_URL = 'https://cdn.tse.jus.br/estatistica/sead/odsele/consulta_cand/consulta_cand_2022.zip';

// ID fixo por UF (1–27) — evita overflow do SQ do TSE (11 dígitos > UINT32)
const UF_ID = [
    'AC' =>  1, 'AL' =>  2, 'AM' =>  3, 'AP' =>  4, 'BA' =>  5, 'CE' =>  6,
    'DF' =>  7, 'ES' =>  8, 'GO' =>  9, 'MA' => 10, 'MG' => 11, 'MS' => 12,
    'MT' => 13, 'PA' => 14, 'PB' => 15, 'PE' => 16, 'PI' => 17, 'PR' => 18,
    'RJ' => 19, 'RN' => 20, 'RO' => 21, 'RR' => 22, 'RS' => 23, 'SC' => 24,
    'SE' => 25, 'SP' => 26, 'TO' => 27,
];

$pdo = Database::connect();
$uploadDir = ROOT . '/public/uploads/parlamentares/' . SOURCE;
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$inicio = microtime(true);

// ── 1. Baixa e extrai CSV ─────────────────────────────────────────────────────

echo "[gov] Baixando CSV do TSE...\n";
$zipTmp = sys_get_temp_dir() . '/tse_cand2022.zip';
$extTmp = sys_get_temp_dir() . '/tse_cand2022';

$csvCached = file_exists($extTmp . '/consulta_cand_2022_BRASIL.csv');

if (!$csvCached) {
    $fp = fopen($zipTmp, 'wb');
    if (!$fp) { echo "[gov] ERRO: Não foi possível criar arquivo temporário\n"; exit(1); }
    $ch = curl_init(CSV_URL);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    $size = file_exists($zipTmp) ? filesize($zipTmp) : 0;
    if ($code !== 200 || $size < 1000) {
        echo "[gov] ERRO: ZIP não acessível (HTTP {$code}, {$size} bytes, erro: {$err})\n"; exit(1);
    }
    echo "[gov] ZIP baixado ({$size} bytes)\n";

    if (!is_dir($extTmp)) mkdir($extTmp, 0755, true);
    $za = new ZipArchive();
    if ($za->open($zipTmp) !== true) { echo "[gov] ERRO: Falha ao abrir ZIP\n"; exit(1); }
    $za->extractTo($extTmp);
    $za->close();
} else {
    echo "[gov] CSV já extraído, usando cache\n";
}

// ── 2. Lê CSV BRASIL e filtra governadores eleitos ───────────────────────────

$csvPath = $extTmp . '/consulta_cand_2022_BRASIL.csv';
if (!file_exists($csvPath)) {
    // Fallback: tenta o arquivo sem sufixo de estado
    $csvPath = glob($extTmp . '/consulta_cand_2022_BR*.csv')[0] ?? '';
}
if (!$csvPath || !file_exists($csvPath)) {
    echo "[gov] ERRO: CSV BRASIL não encontrado em {$extTmp}\n"; exit(1);
}

echo "[gov] Processando CSV...\n";
$handle = fopen($csvPath, 'r');
fgetcsv($handle, 0, ';', '"'); // pula cabeçalho

$governadores = [];
while (($row = fgetcsv($handle, 0, ';', '"')) !== false) {
    if (count($row) < 50) continue;
    // CD_CARGO=3 (Governador), DS_SIT_TOT_TURNO contém ELEITO mas não NÃO ELEITO
    if ((int)$row[13] !== 3) continue;
    $sit = strtoupper($row[49] ?? '');
    if (!str_contains($sit, 'ELEITO') || str_contains($sit, 'N')) continue;

    $uf  = strtoupper(trim($row[10]));
    if ($ufsArg && !in_array($uf, $ufsArg)) continue;

    $toUtf8 = fn(string $s): string => mb_convert_encoding(trim($s), 'UTF-8', 'Windows-1252');
    $toTitle = fn(string $s): string => mb_convert_case(mb_strtolower($toUtf8($s), 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

    $governadores[$uf] = [
        'uf'           => $uf,
        'sq'           => (int)trim($row[15]),
        'nome_completo'=> $toTitle($row[17]),
        'nome_urna'    => $toTitle($row[18]),
        'partido'      => $toUtf8($row[26]),
        'nascimento'   => trim($row[36]), // DD/MM/YYYY
        'genero'       => $toUtf8($row[39]),
        'escolaridade' => $toUtf8($row[41]),
    ];
}
fclose($handle);

echo "[gov] " . count($governadores) . " governadores eleitos encontrados\n\n";

// ── 3. Prepara statements ──────────────────────────────────────────────────────

$stParl = $pdo->prepare("
    INSERT INTO parl_parlamentares
        (source_key, sapl_id, nome_completo, nome_parlamentar, partido_sigla, tse_sq, uf,
         fotografia_url, email, ativo, sincronizado_em, titular)
    VALUES (?,?,?,?,?,?,?, NULL,NULL,1,NOW(),1)
    ON DUPLICATE KEY UPDATE
        nome_completo    = VALUES(nome_completo),
        nome_parlamentar = VALUES(nome_parlamentar),
        partido_sigla    = VALUES(partido_sigla),
        tse_sq           = VALUES(tse_sq),
        ativo            = 1,
        sincronizado_em  = NOW()
");

$stFoto = $pdo->prepare(
    "UPDATE parl_parlamentares SET fotografia_url=? WHERE source_key=? AND sapl_id=?"
);

$stPerfil = $pdo->prepare("
    INSERT INTO parl_perfil_detalhe
        (source_key, sapl_id, situacao, data_nascimento, escolaridade, atualizado_em)
    VALUES (?,?,'Governador',?,?,NOW())
    ON DUPLICATE KEY UPDATE
        situacao         = 'Governador',
        data_nascimento  = VALUES(data_nascimento),
        escolaridade     = VALUES(escolaridade),
        atualizado_em    = NOW()
");

// ── 4. Importa cada governador ────────────────────────────────────────────────

function curlGet(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $r = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($r && $c === 200) ? $r : null;
}

function wikidataPhotoByName(string $nome): ?string {
    $searchUrl = 'https://www.wikidata.org/w/api.php?action=wbsearchentities&search='
        . urlencode($nome) . '&language=pt&format=json&limit=3&type=item';
    $resp = curlGet($searchUrl);
    if (!$resp) return null;
    $data  = json_decode($resp, true) ?? [];
    $items = $data['search'] ?? [];
    if (empty($items)) return null;

    // Pega o primeiro resultado (mais relevante por nome)
    $qid = $items[0]['id'] ?? null;
    if (!$qid) return null;

    usleep(200000);
    $entityUrl = 'https://www.wikidata.org/w/api.php?action=wbgetentities&ids=' . $qid
        . '&format=json&props=claims&languages=pt';
    $resp2 = curlGet($entityUrl);
    if (!$resp2) return null;
    $data2   = json_decode($resp2, true) ?? [];
    $imgName = $data2['entities'][$qid]['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;
    if (!$imgName) return null;
    return 'https://commons.wikimedia.org/wiki/Special:FilePath/' . rawurlencode($imgName);
}

$ok = $semFoto = 0;

foreach ($governadores as $uf => $g) {
    $govId = UF_ID[$uf] ?? null;
    if (!$govId) { echo "  ! UF desconhecida: {$uf}\n"; continue; }

    // Converte data DD/MM/YYYY → YYYY-MM-DD
    $nasc = null;
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $g['nascimento'], $m)) {
        $nasc = "{$m[3]}-{$m[2]}-{$m[1]}";
    }

    $stParl->execute([
        SOURCE, $govId, $g['nome_completo'], $g['nome_urna'],
        $g['partido'], $g['sq'], $uf,
    ]);

    $stPerfil->execute([SOURCE, $govId, $nasc, $g['escolaridade']]);

    // ── Foto via Wikidata (busca por nome) ──
    $localPath    = $uploadDir . '/' . $govId . '.jpg';
    $localPathPng = $uploadDir . '/' . $govId . '.png';
    $localUrl     = '/uploads/parlamentares/' . SOURCE . '/' . $govId . '.jpg';

    if (!$force) {
        if (file_exists($localPath) && filesize($localPath) > 5000) {
            $stFoto->execute([$localUrl, SOURCE, $govId]);
            echo "  ✓ {$g['nome_urna']} ({$uf}) — foto já local\n";
            $ok++; continue;
        }
        if (file_exists($localPathPng) && filesize($localPathPng) > 5000) {
            $pngUrl = '/uploads/parlamentares/' . SOURCE . '/' . $govId . '.png';
            $stFoto->execute([$pngUrl, SOURCE, $govId]);
            echo "  ✓ {$g['nome_urna']} ({$uf}) — foto já local (png)\n";
            $ok++; continue;
        }
    }

    $fotoUrl = wikidataPhotoByName($g['nome_urna']) ?? wikidataPhotoByName($g['nome_completo']);

    if ($fotoUrl) {
        $fp2  = fopen($localPath, 'wb');
        $fc   = curl_init($fotoUrl);
        curl_setopt_array($fc, [
            CURLOPT_FILE           => $fp2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($fc);
        $fcode = curl_getinfo($fc, CURLINFO_HTTP_CODE);
        $mime  = curl_getinfo($fc, CURLINFO_CONTENT_TYPE);
        curl_close($fc);
        fclose($fp2);

        $fsize = file_exists($localPath) ? filesize($localPath) : 0;
        if ($fcode === 200 && $fsize > 5000) {
            if (str_contains($mime, 'png')) {
                $pngPath = $uploadDir . '/' . $govId . '.png';
                rename($localPath, $pngPath);
                $localUrl = '/uploads/parlamentares/' . SOURCE . '/' . $govId . '.png';
            }
            $stFoto->execute([$localUrl, SOURCE, $govId]);
            echo "  ✓ {$g['nome_urna']} ({$uf}) — foto baixada do Wikidata\n";
            $ok++;
        } else {
            @unlink($localPath);
            echo "  - {$g['nome_urna']} ({$uf}) — foto indisponível (HTTP {$fcode}, {$fsize}b)\n";
            $semFoto++;
        }
    } else {
        echo "  - {$g['nome_urna']} ({$uf}) — sem foto no Wikidata\n";
        $semFoto++;
    }

    usleep(300000); // 300ms entre requisições ao Wikidata
}

// ── 5. Atualiza fonte_sincs ───────────────────────────────────────────────────

$total = $ok + $semFoto;
$pdo->prepare("
    INSERT INTO fonte_sincs (source_key, status, iniciado_em, concluido_em, total_parl, detalhes_em)
    VALUES (?, 'ok', NOW(), NOW(), ?, NOW())
    ON DUPLICATE KEY UPDATE status='ok', concluido_em=NOW(), total_parl=?, detalhes_em=NOW()
")->execute([SOURCE, $total, $total]);

// Garante entrada em fontes_legislativas
$pdo->prepare("
    INSERT INTO fontes_legislativas (source_key, label, url)
    VALUES ('governadores','Governadores','https://www.tse.jus.br')
    ON DUPLICATE KEY UPDATE label='Governadores'
")->execute();

$dur = round(microtime(true) - $inicio);
echo "\n[gov] Concluído em {$dur}s — {$ok} com foto, {$semFoto} sem foto, {$total} total\n";
