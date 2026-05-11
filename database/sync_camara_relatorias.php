<?php
/**
 * sync_camara_relatorias.php
 *
 * Baixa os CSVs bulk de proposições da Câmara Federal e popula parl_relatorias
 * com as proposições onde cada deputado consta como relator atual.
 *
 * Fonte: https://dadosabertos.camara.leg.br/arquivos/proposicoes/csv/proposicoes-{ano}.csv
 * Campo: ultimoStatus_uriRelator  → uri com /deputados/{id}
 *
 * Uso:
 *   php database/sync_camara_relatorias.php               — sync 2019-2026
 *   php database/sync_camara_relatorias.php 2003 2026     — range de anos
 *   php database/sync_camara_relatorias.php 2026          — só 2026
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Acesso negado.'); }

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$pdo  = Database::connect();
$args = array_values(array_slice($argv, 1));

// Determina range de anos
if (count($args) === 0) {
    $anoInicio = 2019;
    $anoFim    = (int)date('Y');
} elseif (count($args) === 1) {
    $anoInicio = $anoFim = (int)$args[0];
} else {
    $anoInicio = (int)$args[0];
    $anoFim    = (int)$args[1];
}

echo "=== Sync Relatorias Câmara Federal ({$anoInicio}–{$anoFim}) ===\n\n";

// 1. Carrega todos os sapl_id de deputados federais do banco
$knownIds = $pdo->query(
    "SELECT sapl_id FROM parl_parlamentares WHERE source_key='camara_federal'"
)->fetchAll(PDO::FETCH_COLUMN, 0);
$knownIds = array_flip(array_map('intval', $knownIds));
echo "Deputados no banco: " . count($knownIds) . "\n\n";

if (empty($knownIds)) {
    echo "Nenhum deputado encontrado. Rode sync.php primeiro.\n";
    exit(1);
}

// 2. Prepared statement
$stDel = $pdo->prepare(
    "DELETE FROM parl_relatorias WHERE source_key='camara_federal' AND materia_id=?"
);
$stIns = $pdo->prepare(
    "INSERT IGNORE INTO parl_relatorias
        (source_key, sapl_id, materia_id, materia_str, comissao_str, data_designacao, data_destituicao)
     VALUES ('camara_federal', ?, ?, ?, ?, ?, NULL)"
);

$totalInserido = 0;
$totalIgnorado = 0;

// 3. Processa cada ano
for ($ano = $anoInicio; $ano <= $anoFim; $ano++) {
    $url = "https://dadosabertos.camara.leg.br/arquivos/proposicoes/csv/proposicoes-{$ano}.csv";
    echo "Baixando {$ano}... ";
    flush();

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'KeekConecta/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$body) {
        echo "HTTP {$code} — pulando.\n";
        continue;
    }

    $mb = round(strlen($body) / 1024 / 1024, 1);
    echo "{$mb} MB — parseando... ";
    flush();

    // Escreve em tmpfile para usar fgetcsv (lida com campos multiline)
    $tmp = tmpfile();
    fwrite($tmp, $body);
    unset($body); // libera memória
    rewind($tmp);

    // Lê cabeçalho e remove BOM
    $headers = fgetcsv($tmp, 0, ';');
    if (!$headers) { fclose($tmp); echo "Erro no cabeçalho.\n"; continue; }
    $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF\"");
    $headers    = array_map(fn($h) => trim($h, '"'), $headers);

    $colRelator = array_search('ultimoStatus_uriRelator', $headers);
    $colId      = array_search('id', $headers);
    $colSigla   = array_search('siglaTipo', $headers);
    $colNum     = array_search('numero', $headers);
    $colAno     = array_search('ano', $headers);
    $colEmenta  = array_search('ementa', $headers);
    $colOrgao   = array_search('ultimoStatus_siglaOrgao', $headers);
    $colData    = array_search('ultimoStatus_dataHora', $headers);

    if ($colRelator === false || $colId === false) {
        fclose($tmp);
        echo "Colunas esperadas não encontradas — pulando.\n";
        continue;
    }

    $inseridoAno = 0;
    $pdo->beginTransaction();

    while (($row = fgetcsv($tmp, 0, ';')) !== false) {
        $uri   = trim($row[$colRelator] ?? '', '"');
        if (!$uri || !str_contains($uri, '/deputados/')) continue;

        if (!preg_match('/deputados\/(\d+)/', $uri, $m)) continue;
        $depId = (int)$m[1];

        if (!isset($knownIds[$depId])) continue;

        $propId  = (int)trim($row[$colId]    ?? '', '"');
        $sigla   = trim($row[$colSigla]  ?? '', '"');
        $num     = trim($row[$colNum]    ?? '', '"');
        $anoP    = trim($row[$colAno]    ?? '', '"');
        $ementa  = mb_substr(trim($row[$colEmenta] ?? '', '"'), 0, 200);
        $orgao   = trim($row[$colOrgao]  ?? '', '"');
        $dataRaw = trim($row[$colData]   ?? '', '"');
        $data    = $dataRaw ? substr($dataRaw, 0, 10) : null;

        if (!$propId || !$depId) continue;

        $str = trim("{$sigla} nº {$num}/{$anoP}") . ($ementa ? " - {$ementa}" : '');

        $stDel->execute([$propId]);
        $stIns->execute([$depId, $propId, $str, $orgao, $data]);
        $inseridoAno++;
    }

    $pdo->commit();
    fclose($tmp);

    $totalInserido += $inseridoAno;
    echo "{$inseridoAno} relatorias inseridas.\n";
    flush();
}

echo "\n=== Concluído ===\n";
echo "Total inserido: {$totalInserido}\n";

// Relatório por deputado (top 10)
$st = $pdo->query(
    "SELECT pp.nome_parlamentar, COUNT(*) as total
     FROM parl_relatorias pr
     JOIN parl_parlamentares pp ON pp.source_key=pr.source_key AND pp.sapl_id=pr.sapl_id
     WHERE pr.source_key='camara_federal'
     GROUP BY pp.sapl_id, pp.nome_parlamentar
     ORDER BY total DESC
     LIMIT 10"
);
echo "\nTop 10 relatores:\n";
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  {$r['nome_parlamentar']}: {$r['total']}\n";
}
