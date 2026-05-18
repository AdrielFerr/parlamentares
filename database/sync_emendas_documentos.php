<?php
/**
 * sync_emendas_documentos.php
 *
 * Importa detalhamento por município das emendas parlamentares.
 * Fonte: https://portaldatransparencia.gov.br/download-de-dados/emendas-parlamentares-documentos
 *
 * O CSV de documentos tem uma linha por transferência/OB, contendo município
 * de destino e valores individuais. Isso permite mostrar ao usuário quais
 * municípios foram contemplados por cada emenda com localidade "múltiplo".
 *
 * Uso:
 *   php database/sync_emendas_documentos.php              — importa ano atual
 *   php database/sync_emendas_documentos.php --ano 2024   — filtra por ano
 *   php database/sync_emendas_documentos.php --csv /path  — usa CSV local já extraído
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$pdo  = Database::connect();
$args = array_slice($argv ?? [], 1);

$anoFiltro = null;
$csvLocal  = null;
foreach ($args as $i => $a) {
    if ($a === '--ano' && isset($args[$i + 1])) $anoFiltro = (int)$args[$i + 1];
    if ($a === '--csv' && isset($args[$i + 1])) $csvLocal  = $args[$i + 1];
}

$ano = $anoFiltro ?: (int)date('Y');

// ── Baixa e extrai o CSV ──────────────────────────────────────────────────────
// O Portal da Transparência disponibiliza o CSV de documentos em:
//   https://portaldatransparencia.gov.br/download-de-dados/emendas-parlamentares-documentos
// Selecione o ano desejado e baixe o ZIP. Depois passe o caminho com --csv.
// Exemplo: php sync_emendas_documentos.php --csv /tmp/emendas_docs_2024.csv

$csvPath = $csvLocal;

if (!$csvPath) {
    $tmpZip = sys_get_temp_dir() . '/emendas_docs.zip';
    $tmpDir = sys_get_temp_dir() . '/emendas_docs_' . $ano . '/';

    // O Portal usa uma URL com parâmetro de query para download dos documentos.
    // Tentamos o padrão direto primeiro; se falhar, tentamos o ano anterior com dados completos.
    $urlCandidatos = [
        "https://portaldatransparencia.gov.br/download-de-dados/emendas-parlamentares-documentos/{$ano}",
        "https://portaldatransparencia.gov.br/download-de-dados/emendas-parlamentares-documentos?ano={$ano}",
    ];

    $data = null;
    foreach ($urlCandidatos as $zipUrl) {
        echo "[docs] Tentando: {$zipUrl}\n";
        $ch = curl_init($zipUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER     => ['Accept: application/zip, application/octet-stream, */*'],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($code >= 200 && $code < 300 && $resp && str_contains($ct ?? '', 'zip')) {
            $data = $resp;
            echo "[docs] Sucesso: HTTP {$code} ({$ct})\n";
            break;
        }
        echo "[docs] Falhou: HTTP {$code} ({$ct})\n";
    }

    if (!$data) {
        echo "\n[docs] Não foi possível baixar automaticamente o CSV de documentos.\n";
        echo "[docs] Acesse manualmente:\n";
        echo "[docs]   https://portaldatransparencia.gov.br/download-de-dados/emendas-parlamentares-documentos\n";
        echo "[docs] Selecione o ano {$ano}, baixe o ZIP, extraia e use:\n";
        echo "[docs]   php database/sync_emendas_documentos.php --csv /caminho/para/arquivo.csv\n";
        exit(1);
    }

    file_put_contents($tmpZip, $data);
    echo "[docs] " . round(strlen($data) / 1024 / 1024, 1) . " MB baixados.\n";

    if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);
    $cmd = "powershell -Command \"Expand-Archive -Path '{$tmpZip}' -DestinationPath '{$tmpDir}' -Force\"";
    shell_exec($cmd);

    // Busca qualquer CSV na pasta extraída
    $csvFiles = glob($tmpDir . '*.csv');
    if (!$csvFiles) $csvFiles = glob($tmpDir . '*/*.csv');
    if (!$csvFiles) {
        echo "[docs] ERRO: Nenhum CSV encontrado após extração em {$tmpDir}\n";
        foreach (glob($tmpDir . '*') as $f) echo "  $f\n";
        exit(1);
    }
    $csvPath = $csvFiles[0];
    echo "[docs] Extraído: {$csvPath}\n";
}

// ── Mapeia cabeçalho do CSV ───────────────────────────────────────────────────
// O Portal da Transparência às vezes muda a ordem das colunas entre anos.
// Usamos o cabeçalho para construir um mapa nome → índice.

function normHeader(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $from = ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ',' '];
    $to   = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n','_'];
    return str_replace($from, $to, $s);
}

$f = fopen($csvPath, 'r');
$rawHeader = fgetcsv($f, 0, ';');
if (!$rawHeader) {
    echo "[docs] ERRO: CSV vazio ou formato inválido.\n";
    exit(1);
}

// Converte encoding do cabeçalho
$rawHeader = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'Windows-1252'), $rawHeader);
$colMap = [];
foreach ($rawHeader as $i => $h) {
    $colMap[normHeader($h)] = $i;
}

echo "[docs] Colunas detectadas: " . implode(', ', array_keys($colMap)) . "\n";

// Funções auxiliares para ler coluna por nome (com fallbacks)
function col(array $colMap, array $row, string ...$names): string
{
    foreach ($names as $n) {
        $key = normHeader($n);
        if (isset($colMap[$key]) && isset($row[$colMap[$key]])) {
            return trim($row[$colMap[$key]]);
        }
    }
    return '';
}

function colFloat(array $colMap, array $row, string ...$names): float
{
    $v = col($colMap, $row, ...$names);
    return (float)str_replace(',', '.', str_replace('.', '', $v));
}

// Verifica se as colunas essenciais existem
$essenciais = [
    'codigo_emenda'     => ['Código da Emenda', 'CodEmenda', 'Codigo da Emenda', 'Codigo Emenda', 'codigo_da_emenda'],
    'municipio'         => ['Nome do Município', 'Municipio', 'Município', 'Nome Municipio', 'municipio_de_aplicacao_do_recurso'],
    'valor_empenhado'   => ['Valor Empenhado', 'Empenhado', 'valor_empenhado'],
];

$aviso = false;
foreach ($essenciais as $campo => $nomes) {
    $found = false;
    foreach ($nomes as $n) {
        if (isset($colMap[normHeader($n)])) { $found = true; break; }
    }
    if (!$found) {
        echo "[docs] AVISO: Coluna '{$campo}' não localizada. Nomes tentados: " . implode(', ', $nomes) . "\n";
        $aviso = true;
    }
}
if ($aviso) {
    echo "[docs] Continuando com mapeamento parcial — verifique os dados inseridos.\n";
}

// ── Limpa registros do ano e reinsere ────────────────────────────────────────

$source = 'camara_federal';
$pdo->prepare("DELETE FROM parl_emendas_municipios WHERE source_key=? AND ano=?")->execute([$source, $ano]);
echo "[docs] Registros do ano {$ano} removidos — reimportando.\n";

$stIns = $pdo->prepare(
    "INSERT INTO parl_emendas_municipios
        (source_key, emenda_cod, ano, municipio, uf, valor_empenhado, valor_liquidado, valor_pago)
     VALUES (?,?,?,?,?,?,?,?)"
);

// ── Processa CSV ──────────────────────────────────────────────────────────────

$inicio    = microtime(true);
$inseridos = 0;
$ignorados = 0;
$linha     = 1;

// Mapa emenda_cod → [municipio => índice] para agregar valores da mesma emenda+município
$buffer = [];  // [emenda_cod][municipio_uf] => [emp, liq, pag]

while ($row = fgetcsv($f, 0, ';')) {
    $linha++;
    $row = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'Windows-1252'), $row);

    $codEmenda = col($colMap, $row,
        'Código da Emenda', 'CodEmenda', 'Codigo da Emenda', 'Codigo Emenda', 'Código Emenda',
        'codigo_da_emenda');
    $municipio = col($colMap, $row,
        'Nome do Município', 'Municipio', 'Município', 'Nome Municipio', 'Nome do Municipio',
        'municipio_de_aplicacao_do_recurso');
    $uf        = col($colMap, $row,
        'UF', 'Sigla UF', 'Estado', 'Sigla do Estado',
        'uf_de_aplicacao_do_recurso');
    $valEmp    = colFloat($colMap, $row, 'Valor Empenhado', 'Empenhado', 'valor_empenhado');
    $valLiq    = colFloat($colMap, $row, 'Valor Liquidado', 'Liquidado', 'valor_liquidado');
    $valPag    = colFloat($colMap, $row, 'Valor Pago', 'Pago', 'valor_pago');

    if (!$codEmenda || !$municipio) { $ignorados++; continue; }
    if (strtolower($municipio) === 'sem informação' || strtolower($municipio) === 'sem informacao' || $uf === '-1') { $ignorados++; continue; }

    // Agrega múltiplos documentos para o mesmo par emenda+município
    $key = "{$codEmenda}|||{$municipio}|||{$uf}";
    if (!isset($buffer[$key])) {
        $buffer[$key] = ['cod' => $codEmenda, 'mun' => $municipio, 'uf' => $uf, 'emp' => 0, 'liq' => 0, 'pag' => 0];
    }
    $buffer[$key]['emp'] += $valEmp;
    $buffer[$key]['liq'] += $valLiq;
    $buffer[$key]['pag'] += $valPag;

    if ($linha % 50000 === 0) {
        echo "  {$linha} linhas lidas, buffer: " . count($buffer) . " pares emenda+município...\n";
        flush();
    }
}
fclose($f);

echo "[docs] CSV lido em " . round(microtime(true) - $inicio) . "s. Inserindo " . count($buffer) . " registros...\n";

// Insere o buffer agregado
$pdo->beginTransaction();
foreach ($buffer as $item) {
    try {
        $stIns->execute([$source, $item['cod'], $ano, $item['mun'], $item['uf'],
                         $item['emp'], $item['liq'], $item['pag']]);
        $inseridos++;
    } catch (PDOException $e) {
        // ignora duplicata
    }
    if ($inseridos % 5000 === 0 && $inseridos > 0) {
        $pdo->commit();
        $pdo->beginTransaction();
        echo "  {$inseridos} inseridos...\n"; flush();
    }
}
$pdo->commit();

$dur = round(microtime(true) - $inicio);
echo "\n[docs] Concluído em {$dur}s:\n";
echo "  Inseridos: {$inseridos} pares emenda+município\n";
echo "  Ignorados: {$ignorados} (sem emenda_cod ou município)\n";
