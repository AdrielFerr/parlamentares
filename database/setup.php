<?php
/**
 * Executa uma vez para criar o banco, tabelas e seed inicial.
 * Acesse: http://localhost/edidesk/database/setup.php
 * Delete ou proteja este arquivo após rodar.
 */

$host    = 'localhost';
$user    = 'root';
$pass    = '';
$dbName  = 'edidesk';
$charset = 'utf8mb4';

try {
    // Conecta sem banco para criar
    $pdo = new PDO("mysql:host={$host};charset={$charset}", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

    // ── Tabelas ──────────────────────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS clientes (
        id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nome      VARCHAR(200) NOT NULL,
        ativo     TINYINT(1) NOT NULL DEFAULT 1,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT UNSIGNED NULL,
        nome       VARCHAR(200) NOT NULL,
        email      VARCHAR(200) NOT NULL UNIQUE,
        senha_hash VARCHAR(255) NOT NULL,
        nivel      TINYINT(1) NOT NULL DEFAULT 4,
        ativo      TINYINT(1) NOT NULL DEFAULT 1,
        criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fontes_legislativas (
        id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_key VARCHAR(50) NOT NULL UNIQUE,
        label      VARCHAR(200) NOT NULL,
        url        VARCHAR(300) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS projetos (
        id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cliente_id     INT UNSIGNED NOT NULL,
        fonte_id       INT UNSIGNED NULL,
        nome           VARCHAR(200) NOT NULL,
        openai_key_enc TEXT NULL,
        ativo          TINYINT(1) NOT NULL DEFAULT 1,
        criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
        conteudo   MEDIUMTEXT NOT NULL,
        ativo      TINYINT(1) NOT NULL DEFAULT 1,
        criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (projeto_id) REFERENCES projetos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS parlamentares_cache (
        id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_key    VARCHAR(50) NOT NULL,
        sapl_id       INT UNSIGNED NOT NULL,
        dados_json    MEDIUMTEXT NOT NULL,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

    // ── Migrações: colunas adicionais em projetos ────────────
    // Adiciona colunas que podem não existir em instalações anteriores
    $colunas = [
        'openai_model'    => "ALTER TABLE projetos ADD COLUMN IF NOT EXISTS openai_model VARCHAR(50) NULL DEFAULT 'gpt-4o'",
        'prompt_sistema'  => "ALTER TABLE projetos ADD COLUMN IF NOT EXISTS prompt_sistema TEXT NULL",
        'dashboards_json' => "ALTER TABLE projetos ADD COLUMN IF NOT EXISTS dashboards_json TEXT NULL",
    ];
    foreach ($colunas as $nome => $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* coluna já existe */ }
    }

    // ── Seeds: fontes legislativas ────────────────────────
    $fontes = [
        ['cmjp',           'C.M. João Pessoa',     'http://sapl.camarajp.pb.gov.br'],
        ['bayeux',         'C.M. Bayeux',           'http://sapl.bayeux.pb.leg.br'],
        ['cabedelo',       'C.M. Cabedelo',         'http://sapl.cabedelo.pb.leg.br'],
        ['campina',        'C.M. Campina Grande',   'http://sapl.campinagrande.pb.leg.br'],
        ['santarita',      'C.M. Santa Rita',       'http://sapl.santarita.pb.leg.br'],
        ['alpb',           'ALPB',                  'http://sapl.al.pb.leg.br'],
        ['alsp',           'ALESP',                 'http://sapl.al.sp.leg.br'],
        ['alrj',           'ALERJ',                 'http://sapl.al.rj.leg.br'],
        ['brasilia',       'C.M. Brasília',         'http://sapl.cl.df.gov.br'],
        ['alpe',           'ALEPE',                 'http://sapl.al.pe.leg.br'],
        ['alrn',           'ALERN',                 'http://sapl.al.rn.leg.br'],
        ['alce',           'ALECE',                 'http://sapl.al.ce.leg.br'],
        ['alba',           'ALBA',                  'http://sapl.al.ba.leg.br'],
        ['alsc',           'ALESC',                 'http://sapl.al.sc.leg.br'],
        ['almg',           'ALMG',                  'http://sapl.al.mg.leg.br'],
        ['camara_federal', 'Câmara Federal',         'https://dadosabertos.camara.leg.br'],
        ['senado',         'Senado Federal',         'https://legis.senado.leg.br'],
    ];

    $st = $pdo->prepare("INSERT IGNORE INTO fontes_legislativas (source_key, label, url) VALUES (?, ?, ?)");
    foreach ($fontes as [$key, $label, $url]) {
        $st->execute([$key, $label, $url]);
    }

    // ── Seed: superadmin ─────────────────────────────────
    $adminEmail = 'admin@keekconecta.com.br';
    $adminSenha = 'keek@2025';
    $adminHash  = password_hash($adminSenha, PASSWORD_BCRYPT);

    $check = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
    $check->execute([$adminEmail]);
    if (!$check->fetch()) {
        $pdo->prepare("INSERT INTO usuarios (nome, email, senha_hash, nivel, ativo) VALUES (?, ?, ?, 0, 1)")
            ->execute(['SuperAdmin', $adminEmail, $adminHash]);
    }

    echo "<pre style='font-family:monospace;padding:20px'>";
    echo "✅ Banco <strong>{$dbName}</strong> criado/atualizado com sucesso!\n\n";
    echo "Tabelas criadas:\n";
    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        echo "  • {$t}\n";
    }
    echo "\nSuperAdmin criado:\n";
    echo "  E-mail : {$adminEmail}\n";
    echo "  Senha  : {$adminSenha}\n\n";
    echo "<strong style='color:red'>⚠ Delete ou proteja este arquivo após usar!</strong>\n";
    echo "</pre>";

} catch (PDOException $e) {
    echo "<pre style='color:red'>Erro: " . htmlspecialchars($e->getMessage()) . "</pre>";
}
