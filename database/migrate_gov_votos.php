<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$pdo  = Database::connect();
$cols = $pdo->query("SHOW COLUMNS FROM parl_mandatos_gov")->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('votos', $cols)) {
    $pdo->exec("ALTER TABLE parl_mandatos_gov ADD COLUMN votos BIGINT UNSIGNED NULL AFTER resultado");
    echo "OK: coluna votos adicionada\n";
} else {
    echo "OK: coluna votos já existe\n";
}

if (!in_array('pct_votos', $cols)) {
    $pdo->exec("ALTER TABLE parl_mandatos_gov ADD COLUMN pct_votos DECIMAL(5,2) NULL AFTER votos");
    echo "OK: coluna pct_votos adicionada\n";
} else {
    echo "OK: coluna pct_votos já existe\n";
}

echo "Concluído.\n";
