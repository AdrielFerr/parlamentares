<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';
$pdo = Database::connect();

// Remove comissoes where same (source, sapl_id, comissao_str) but different data_inicio
$n = $pdo->exec('
    DELETE c FROM parl_comissoes c
    INNER JOIN (
        SELECT MIN(id) AS keep_id, source_key, sapl_id, comissao_str
        FROM parl_comissoes
        GROUP BY source_key, sapl_id, comissao_str
        HAVING COUNT(*) > 1
    ) k ON c.source_key = k.source_key
       AND c.sapl_id = k.sapl_id
       AND c.comissao_str = k.comissao_str
    WHERE c.id != k.keep_id
');
echo "comissoes restantes deduplicadas: $n\n";

// Add UNIQUE (source_key, sapl_id, comissao_str) — sem data_inicio
try {
    $pdo->exec('ALTER TABLE parl_comissoes ADD UNIQUE KEY uniq_com (source_key, sapl_id, comissao_str(120))');
    echo "UNIQUE comissoes adicionada\n";
} catch (Exception $e) {
    echo "UNIQUE comissoes: " . $e->getMessage() . "\n";
}

// Add UNIQUE relatorias
try {
    $pdo->exec('ALTER TABLE parl_relatorias ADD UNIQUE KEY uniq_rel (source_key, sapl_id, materia_id)');
    echo "UNIQUE relatorias adicionada\n";
} catch (Exception $e) {
    echo "UNIQUE relatorias: " . $e->getMessage() . "\n";
}

echo "Total comissoes: " . $pdo->query('SELECT COUNT(*) FROM parl_comissoes')->fetchColumn() . "\n";
echo "Total relatorias: " . $pdo->query('SELECT COUNT(*) FROM parl_relatorias')->fetchColumn() . "\n";
