<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$pdo = Database::connect();
$sql = file_get_contents(__DIR__ . '/migrate_gov_extras.sql');

foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    if (!$stmt || str_starts_with($stmt, '--')) continue;
    try {
        $pdo->exec($stmt);
        echo "OK: " . substr($stmt, 0, 60) . "...\n";
    } catch (PDOException $e) {
        echo "SKIP (" . $e->getMessage() . "): " . substr($stmt, 0, 60) . "...\n";
    }
}

echo "\nMigração concluída.\n";
