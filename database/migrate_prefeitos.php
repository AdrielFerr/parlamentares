<?php
/**
 * Cria tabelas e colunas para o módulo de prefeitos de regiões metropolitanas.
 *
 * Tabelas criadas:
 *   municipios_rm       — municípios das RMs de interesse (populada via IBGE API)
 *   parl_mandatos_pref  — mandatos dos prefeitos (eleição 2024 → 2025-2028)
 *
 * Colunas adicionadas a parl_parlamentares:
 *   cd_municipio  — código IBGE de 7 dígitos do município
 *   nm_municipio  — nome do município
 *   nm_rm         — nome da RM à qual o município pertence
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$pdo = Database::connect();

// ── 1. municipios_rm ─────────────────────────────────────────────────────────
$pdo->exec("
CREATE TABLE IF NOT EXISTS municipios_rm (
  cd_municipio INT UNSIGNED  NOT NULL,
  nm_municipio VARCHAR(120)  NOT NULL,
  uf           CHAR(2)       NOT NULL,
  cd_rm        VARCHAR(10)   NOT NULL,
  nm_rm        VARCHAR(220)  NOT NULL,
  PRIMARY KEY (cd_municipio),
  KEY idx_uf (uf),
  KEY idx_rm (cd_rm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "OK: tabela municipios_rm criada/verificada\n";

// ── 2. parl_mandatos_pref ────────────────────────────────────────────────────
$pdo->exec("
CREATE TABLE IF NOT EXISTS parl_mandatos_pref (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_key  VARCHAR(50)       NOT NULL,
  sapl_id     INT UNSIGNED      NOT NULL,
  ano_eleicao SMALLINT UNSIGNED NOT NULL,
  periodo_ini SMALLINT UNSIGNED NOT NULL,
  periodo_fim SMALLINT UNSIGNED NOT NULL,
  turno       TINYINT UNSIGNED  NULL,
  coligacao   VARCHAR(300)      NULL,
  resultado   VARCHAR(100)      NULL,
  votos       BIGINT UNSIGNED   NULL,
  pct_votos   DECIMAL(5,2)      NULL,
  UNIQUE KEY uq_pref (source_key, sapl_id, ano_eleicao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "OK: tabela parl_mandatos_pref criada/verificada\n";

// ── 3. Colunas extras em parl_parlamentares ──────────────────────────────────
$cols = $pdo->query("SHOW COLUMNS FROM parl_parlamentares")->fetchAll(PDO::FETCH_COLUMN);

$alterations = [
    'cd_municipio' => "ADD COLUMN cd_municipio INT UNSIGNED NULL AFTER uf",
    'nm_municipio' => "ADD COLUMN nm_municipio VARCHAR(120) NULL AFTER cd_municipio",
    'nm_rm'        => "ADD COLUMN nm_rm VARCHAR(220) NULL AFTER nm_municipio",
];
foreach ($alterations as $col => $alter) {
    if (!in_array($col, $cols)) {
        $pdo->exec("ALTER TABLE parl_parlamentares {$alter}");
        echo "OK: coluna {$col} adicionada a parl_parlamentares\n";
    } else {
        echo "OK: coluna {$col} já existe\n";
    }
}

// ── 4. Popula municipios_rm via IBGE API (RJ, PB, PE) ───────────────────────
echo "\nPopulando municipios_rm via IBGE API...\n";

$ch = curl_init('https://servicodados.ibge.gov.br/api/v1/localidades/regioes-metropolitanas');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
]);
$body = curl_exec($ch);
curl_close($ch);

if (!$body) {
    echo "ERRO: não foi possível acessar a API do IBGE\n";
    exit(1);
}

$rms = json_decode($body, true);
if (!$rms) {
    echo "ERRO: resposta inválida da API do IBGE\n";
    exit(1);
}

$ufsAlvo = ['RJ', 'PB', 'PE'];

$stIns = $pdo->prepare(
    "INSERT INTO municipios_rm (cd_municipio, nm_municipio, uf, cd_rm, nm_rm)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE nm_rm=VALUES(nm_rm)"
);

$total = 0;
foreach ($rms as $rm) {
    $uf = $rm['UF']['sigla'] ?? '';
    if (!in_array($uf, $ufsAlvo)) continue;

    $cdRm = (string)($rm['id'] ?? '');
    $nmRm = $rm['nome'] ?? '';

    foreach ($rm['municipios'] as $mun) {
        $stIns->execute([(int)$mun['id'], $mun['nome'], $uf, $cdRm, $nmRm]);
        $total++;
    }
    echo "  ✓ {$nmRm} ({$uf}): " . count($rm['municipios']) . " municípios\n";
}

echo "\nTotal municípios inseridos: {$total}\n";
echo "Concluído.\n";
