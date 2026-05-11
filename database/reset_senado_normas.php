<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';
$pdo = Database::connect();

$del = $pdo->exec("DELETE FROM parl_normas WHERE source_key='senado'");
echo "Deletados: " . number_format($del) . " registros senado\n";

// Limpar cache senado de normas para forçar nova busca
$delCache = $pdo->exec("DELETE FROM sapl_cache WHERE source='senado' AND cache_key LIKE '/norma/%'");
echo "Cache normas senado removido: {$delCache} entradas\n";

$total = $pdo->query("SELECT COUNT(*) FROM parl_normas WHERE source_key='senado'")->fetchColumn();
echo "Senado normas restantes: {$total}\n";
