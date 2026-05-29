<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$pdo  = Database::connect();
$cols = $pdo->query('DESCRIBE parl_perfil_detalhe')->fetchAll(PDO::FETCH_COLUMN);

$toAdd = [
    'votos_2022'     => "BIGINT UNSIGNED NULL AFTER patrimonio",
    'coligacao_2022' => "VARCHAR(300) NULL AFTER votos_2022",
    'resultado_2022' => "VARCHAR(100) NULL AFTER coligacao_2022",
    'turno_2022'     => "TINYINT UNSIGNED NULL AFTER resultado_2022",
];

foreach ($toAdd as $col => $def) {
    if (in_array($col, $cols)) { echo "SKIP: $col já existe\n"; continue; }
    $pdo->exec("ALTER TABLE parl_perfil_detalhe ADD COLUMN $col $def");
    echo "OK: $col adicionado\n";
}
echo "Concluído.\n";
