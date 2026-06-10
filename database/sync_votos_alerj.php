<?php
/**
 * sync_votos_alerj.php
 *
 * Preenche votos_recebidos dos deputados ALERJ cruzando com o CSV de votação do TSE 2022.
 * Usa range request para baixar apenas o fragmento RJ (~12.5MB) do ZIP de 551MB.
 * CD_CARGO=7 = Deputado Estadual, NR_TURNO=1.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

// Posição exata do arquivo RJ dentro do ZIP do TSE 2022
// Obtida via inspeção do Central Directory (ver tmp_zip_peek.php)
const ZIP_URL    = 'https://cdn.tse.jus.br/estatistica/sead/odsele/votacao_candidato_munzona/votacao_candidato_munzona_2022.zip';
const RJ_DATA_START = 225288022;
const RJ_DATA_END   = 238373986;
const RJ_CSV_CACHE  = 'tse_votos_rj_2022.csv';

$pdo    = Database::connect();
$inicio = microtime(true);

// ── 1. Normaliza nome para comparação fuzzy ───────────────────────────────────

function normNome(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = str_replace(
        ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ'],
        ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'],
        $s
    );
    $s = preg_replace('/[^a-z\s]/', '', $s);
    return preg_replace('/\s+/', ' ', trim($s));
}

// ── 2. Carrega deputados ALERJ sem votos do banco ─────────────────────────────

$semVotos = $pdo->query(
    "SELECT pp.sapl_id, pp.nome_parlamentar, pp.nome_completo, m.id mandato_id
     FROM parl_parlamentares pp
     JOIN parl_mandatos m ON m.source_key=pp.source_key AND m.parlamentar_id=pp.sapl_id
     WHERE pp.source_key='alrj' AND (m.votos_recebidos IS NULL OR m.votos_recebidos='')"
)->fetchAll(PDO::FETCH_ASSOC);

echo "[tse] " . count($semVotos) . " deputados ALERJ sem votos\n";
if (!$semVotos) { echo "[tse] Nada a fazer.\n"; exit(0); }

// ── 3. Baixa / usa cache do fragmento CSV RJ ─────────────────────────────────

$csvPath = sys_get_temp_dir() . '/' . RJ_CSV_CACHE;

if (!file_exists($csvPath) || filesize($csvPath) < 1000000) {
    echo "[tse] Baixando fragmento RJ do ZIP TSE 2022 (~12.5MB)...\n";

    $ch = curl_init(ZIP_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_RANGE          => RJ_DATA_START . '-' . RJ_DATA_END,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
    ]);
    $compressed = curl_exec($ch);
    $code       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 206 || strlen($compressed) < 1000000) {
        echo "[tse] ERRO: range request falhou (HTTP {$code}, " . strlen($compressed) . " bytes)\n"; exit(1);
    }
    echo "[tse] Baixado " . round(strlen($compressed)/1024/1024, 1) . "MB. Descomprimindo...\n";

    // Descomprime DEFLATE raw → CSV (stream para evitar pico de memória)
    $ctx = inflate_init(ZLIB_ENCODING_RAW);
    $fout = fopen($csvPath, 'wb');
    $chunkSize = 65536;
    for ($i = 0; $i < strlen($compressed); $i += $chunkSize) {
        $chunk = substr($compressed, $i, $chunkSize);
        $dec   = inflate_add($ctx, $chunk);
        if ($dec === false) { echo "[tse] ERRO: falha na descompressão\n"; fclose($fout); unlink($csvPath); exit(1); }
        fwrite($fout, $dec);
    }
    inflate_add($ctx, '', ZLIB_FINISH);
    fclose($fout);
    unset($compressed);
    echo "[tse] CSV descomprimido: " . round(filesize($csvPath)/1024/1024, 1) . "MB\n";
} else {
    echo "[tse] Usando CSV em cache (" . round(filesize($csvPath)/1024/1024, 1) . "MB)\n";
}

// ── 4. Lê header e encontra índices das colunas ──────────────────────────────

echo "[tse] Processando CSV...\n";
$toUtf8 = fn(string $s): string => mb_convert_encoding(trim($s), 'UTF-8', 'Windows-1252');

$handle    = fopen($csvPath, 'r');
$headerRaw = fgetcsv($handle, 0, ';', '"');
$header    = array_map(fn($h) => strtoupper(trim($h, " \t\n\r\0\x0B\"")), $headerRaw);

$colIdx = [];
foreach (['CD_CARGO', 'NR_TURNO', 'NM_URNA_CANDIDATO', 'NM_CANDIDATO', 'QT_VOTOS_NOMINAIS'] as $col) {
    $idx = array_search($col, $header);
    if ($idx === false) {
        echo "[tse] ERRO: coluna {$col} não encontrada. Colunas: " . implode(', ', array_slice($header, 0, 20)) . "\n";
        fclose($handle);
        exit(1);
    }
    $colIdx[$col] = $idx;
}

echo "[tse] Colunas encontradas: CD_CARGO={$colIdx['CD_CARGO']}, NR_TURNO={$colIdx['NR_TURNO']}, "
   . "NM_URNA={$colIdx['NM_URNA_CANDIDATO']}, QT_VOTOS={$colIdx['QT_VOTOS_NOMINAIS']}\n";

// ── 5. Agrega votos por candidato (soma todas as zonas) ──────────────────────

$tseVotos = []; // normNome => votos (acumulado por zona)

while (($row = fgetcsv($handle, 0, ';', '"')) !== false) {
    if ((int)($row[$colIdx['CD_CARGO']] ?? 0) !== 7) continue; // Dep. Estadual
    if ((int)($row[$colIdx['NR_TURNO']] ?? 0) !== 1) continue; // 1º turno

    $votos = (int)trim($row[$colIdx['QT_VOTOS_NOMINAIS']] ?? 0);
    if ($votos < 1) continue;

    $nomeUrna = normNome($toUtf8($row[$colIdx['NM_URNA_CANDIDATO']] ?? ''));
    $nomeCand = normNome($toUtf8($row[$colIdx['NM_CANDIDATO']] ?? ''));

    if ($nomeUrna) $tseVotos[$nomeUrna] = ($tseVotos[$nomeUrna] ?? 0) + $votos;
    if ($nomeCand) $tseVotos[$nomeCand] = ($tseVotos[$nomeCand] ?? 0) + $votos;
}
fclose($handle);

echo "[tse] " . count($tseVotos) . " candidatos TSE após agregação (Dep. Estadual RJ 1º turno)\n\n";

// ── 6. Cruza e atualiza votos ─────────────────────────────────────────────────

$stUpdate = $pdo->prepare("UPDATE parl_mandatos SET votos_recebidos=? WHERE id=?");

$match = $noMatch = 0;

foreach ($semVotos as $dep) {
    $nomeParl = normNome($dep['nome_parlamentar']);
    $nomeComp = normNome($dep['nome_completo'] ?? '');
    $votos    = null;

    // Match exato
    foreach (array_filter([$nomeParl, $nomeComp]) as $n) {
        if (isset($tseVotos[$n])) { $votos = $tseVotos[$n]; break; }
    }

    // Match parcial — nome ALERJ contido no TSE ou vice-versa
    if (!$votos) {
        foreach ($tseVotos as $tseName => $v) {
            if ($nomeParl && (str_contains($tseName, $nomeParl) || str_contains($nomeParl, $tseName))) {
                $votos = $v; break;
            }
            if ($nomeComp && (str_contains($tseName, $nomeComp) || str_contains($nomeComp, $tseName))) {
                $votos = $v; break;
            }
        }
    }

    if ($votos) {
        $stUpdate->execute([$votos, $dep['mandato_id']]);
        echo "  ✓ [{$dep['sapl_id']}] {$dep['nome_parlamentar']} → {$votos} votos\n";
        $match++;
    } else {
        echo "  ✗ [{$dep['sapl_id']}] {$dep['nome_parlamentar']} — sem match no TSE\n";
        $noMatch++;
    }
}

$dur = round(microtime(true) - $inicio);
echo "\n[tse] Concluído em {$dur}s — {$match} atualizados, {$noMatch} sem match\n";
