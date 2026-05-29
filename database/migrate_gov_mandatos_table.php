<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$pdo = Database::connect();

$pdo->exec("
CREATE TABLE IF NOT EXISTS parl_mandatos_gov (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_key  VARCHAR(50)       NOT NULL,
  sapl_id     INT UNSIGNED      NOT NULL,
  ano_eleicao SMALLINT UNSIGNED NOT NULL,
  periodo_ini SMALLINT UNSIGNED NOT NULL,
  periodo_fim SMALLINT UNSIGNED NOT NULL,
  turno       TINYINT UNSIGNED  NULL,
  coligacao   VARCHAR(300)      NULL,
  resultado   VARCHAR(100)      NULL,
  UNIQUE KEY uq_gov_mandato (source_key, sapl_id, ano_eleicao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "OK: tabela parl_mandatos_gov criada/verificada\n";
