<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';
$pdo = Database::connect();

echo "=== Deletando normas incorretas do senado ===\n";
$del = $pdo->exec("DELETE FROM parl_normas WHERE source_key='senado'");
echo "  Deletados: " . number_format($del) . " registros\n";

echo "\n=== Normas restantes por source ===\n";
$st = $pdo->query('SELECT source_key, COUNT(*) as total FROM parl_normas GROUP BY source_key');
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
    echo "  {$r['source_key']}: " . number_format($r['total']) . "\n";

echo "\n=== Situacoes em parl_materias (senado) ===\n";
$st = $pdo->query("SELECT situacao, COUNT(*) as n FROM parl_materias WHERE source_key='senado' GROUP BY situacao ORDER BY n DESC LIMIT 20");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
    echo "  " . str_pad($r['situacao'] ?? '(null)', 50) . ": " . number_format($r['n']) . "\n";

echo "\n=== Sample de materias com situacao nao-nula (senado) ===\n";
$st = $pdo->query("SELECT sapl_id, materia_id, tipo_sigla, numero, ano, situacao, ementa FROM parl_materias WHERE source_key='senado' AND situacao IS NOT NULL AND situacao != '' LIMIT 10");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
    echo "  [{$r['sapl_id']}] {$r['tipo_sigla']} {$r['numero']}/{$r['ano']} sit={$r['situacao']} — " . substr($r['ementa'],0,40) . "\n";

echo "\n=== Total materias senado ===\n";
echo "  " . $pdo->query("SELECT COUNT(*) FROM parl_materias WHERE source_key='senado'")->fetchColumn() . " materias\n";

echo "\n=== Colunas de parl_materias ===\n";
$st = $pdo->query("SHOW COLUMNS FROM parl_materias");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
    echo "  {$r['Field']} ({$r['Type']})\n";
