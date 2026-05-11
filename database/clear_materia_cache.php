<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';
$pdo = Database::connect();

$n = $pdo->exec("DELETE FROM sapl_cache WHERE source='senado' AND cache_key LIKE '/materia/materialegislativa/%'");
echo "Removidas (materialegislativa): $n\n";

$n2 = $pdo->exec("DELETE FROM sapl_cache WHERE source='senado' AND cache_key LIKE '/norma/%'");
echo "Removidas (norma): $n2\n";

$total = $pdo->query("SELECT COUNT(*) FROM sapl_cache WHERE source='senado'")->fetchColumn();
echo "Restam no cache senado: $total\n";
