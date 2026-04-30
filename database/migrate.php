<?php
/**
 * Migration runner — executado via CLI pelo docker-entrypoint.sh
 * Uso: php database/migrate.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';

echo "[migrate] Conectando ao MySQL em " . DB_HOST . "...\n";

$maxTentativas = 30;
for ($i = 1; $i <= $maxTentativas; $i++) {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET,
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        echo "[migrate] Conexão estabelecida.\n";
        break;
    } catch (PDOException $e) {
        if ($i === $maxTentativas) {
            echo "[migrate] ERRO: não foi possível conectar após {$maxTentativas} tentativas.\n";
            echo "[migrate] " . $e->getMessage() . "\n";
            exit(1);
        }
        echo "[migrate] Aguardando MySQL... ({$i}/{$maxTentativas})\n";
        sleep(2);
    }
}

$db = DB_NAME;
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$db}`");

// ── Tabelas ────────────────────────────────────────────────────────────────

$pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome      VARCHAR(200) NOT NULL,
    ativo     TINYINT(1)  NOT NULL DEFAULT 1,
    criado_em DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NULL,
    nome       VARCHAR(200) NOT NULL,
    email      VARCHAR(200) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    nivel      TINYINT(1)  NOT NULL DEFAULT 4
                COMMENT '0=SuperAdmin,1=Administrador,2=Gestor,3=Analista,4=Visualizador',
    ativo      TINYINT(1)  NOT NULL DEFAULT 1,
    criado_em  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS fontes_legislativas (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key VARCHAR(50)  NOT NULL UNIQUE,
    label      VARCHAR(200) NOT NULL,
    url        VARCHAR(300) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS projetos (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id       INT UNSIGNED NOT NULL,
    fonte_id         INT UNSIGNED NULL,
    nome             VARCHAR(200) NOT NULL,
    openai_key_enc   TEXT         NULL COMMENT 'AES-256-CBC encrypted',
    openai_model     VARCHAR(50)  NOT NULL DEFAULT 'gpt-4o',
    prompt_sistema   TEXT         NULL,
    dashboards_json  TEXT         NULL,
    parl_total       INT UNSIGNED NOT NULL DEFAULT 0,
    ativo            TINYINT(1)  NOT NULL DEFAULT 1,
    criado_em        DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    FOREIGN KEY (fonte_id)   REFERENCES fontes_legislativas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS sentinela_conversas (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    projeto_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    pergunta   TEXT NOT NULL,
    resposta   TEXT NOT NULL,
    criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (projeto_id) REFERENCES projetos(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS sentinela_arquivos (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    projeto_id INT UNSIGNED NOT NULL,
    nome       VARCHAR(300) NOT NULL,
    conteudo   MEDIUMTEXT   NOT NULL,
    ativo      TINYINT(1)  NOT NULL DEFAULT 1,
    criado_em  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (projeto_id) REFERENCES projetos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS parlamentares_cache (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key    VARCHAR(50)  NOT NULL,
    sapl_id       INT UNSIGNED NOT NULL,
    dados_json    MEDIUMTEXT   NOT NULL,
    atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_source_sapl (source_key, sapl_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS sapl_cache (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source     VARCHAR(50)  NOT NULL,
    cache_key  VARCHAR(500) NOT NULL,
    data       LONGTEXT     NOT NULL,
    expires_at DATETIME     NOT NULL,
    updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_source_key (source, cache_key(255)),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "[migrate] Tabelas verificadas/criadas.\n";

// ── Migrations (colunas adicionais em instalações antigas) ─────────────────

$alteracoes = [
    "ALTER TABLE projetos ADD COLUMN IF NOT EXISTS openai_model    VARCHAR(50) NOT NULL DEFAULT 'gpt-4o'",
    "ALTER TABLE projetos ADD COLUMN IF NOT EXISTS prompt_sistema  TEXT NULL",
    "ALTER TABLE projetos ADD COLUMN IF NOT EXISTS dashboards_json TEXT NULL",
    "ALTER TABLE projetos ADD COLUMN IF NOT EXISTS parl_total      INT UNSIGNED NOT NULL DEFAULT 0",
];
foreach ($alteracoes as $sql) {
    try { $pdo->exec($sql); } catch (PDOException $e) { /* coluna já existe */ }
}

echo "[migrate] Migrations aplicadas.\n";

// ── Seeds: fontes legislativas ─────────────────────────────────────────────

$fontes = [
    ['cmjp',           'C.M. João Pessoa',   'https://sapl.joaopessoa.pb.leg.br'],
    ['bayeux',         'C.M. Bayeux',         'https://sapl.bayeux.pb.leg.br'],
    ['cabedelo',       'C.M. Cabedelo',       'https://sapl.cabedelo.pb.leg.br'],
    ['campina',        'C.M. Campina Grande', 'https://sapl.campinagrande.pb.leg.br'],
    ['santarita',      'C.M. Santa Rita',     'https://sapl.santarita.pb.leg.br'],
    ['alpb',           'ALPB',                'https://sapl.al.pb.leg.br'],
    ['alsp',           'ALESP',               'https://sapl.al.sp.leg.br'],
    ['alrj',           'ALERJ',               'https://sapl.alerj.rj.gov.br'],
    ['brasilia',       'C.M. Brasília',       'https://sapl.cl.df.leg.br'],
    ['alpe',           'ALEPE',               'https://sapl.alepe.pe.leg.br'],
    ['alrn',           'ALERN',               'https://sapl.al.rn.leg.br'],
    ['alce',           'ALECE',               'https://sapl.al.ce.leg.br'],
    ['alba',           'ALBA',                'https://sapl.al.ba.leg.br'],
    ['alsc',           'ALESC',               'https://sapl.alesc.sc.leg.br'],
    ['almg',           'ALMG',                'https://sapl.almg.gov.br'],
    ['camara_federal', 'Câmara Federal',      'https://dadosabertos.camara.leg.br'],
    ['senado',         'Senado Federal',      'https://legis.senado.leg.br'],
];

$st = $pdo->prepare("INSERT IGNORE INTO fontes_legislativas (source_key, label, url) VALUES (?,?,?)");
foreach ($fontes as [$key, $label, $url]) {
    $st->execute([$key, $label, $url]);
}
echo "[migrate] Fontes legislativas sincronizadas.\n";

// ── Seed: SuperAdmin ───────────────────────────────────────────────────────

$adminEmail = _env('ADMIN_EMAIL', 'admin@keekconecta.com.br');
$adminSenha = _env('ADMIN_PASS',  'keek@2025');

$check = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
$check->execute([$adminEmail]);
if (!$check->fetch()) {
    $hash = password_hash($adminSenha, PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, nivel, ativo) VALUES (?,?,?,0,1)")
        ->execute(['SuperAdmin', $adminEmail, $hash]);
    echo "[migrate] SuperAdmin criado: {$adminEmail}\n";
} else {
    echo "[migrate] SuperAdmin já existe.\n";
}

echo "[migrate] Concluído.\n";
