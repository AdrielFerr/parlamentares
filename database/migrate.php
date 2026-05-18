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

$pdo->exec("CREATE TABLE IF NOT EXISTS projeto_admins (
    projeto_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (projeto_id, usuario_id),
    FOREIGN KEY (projeto_id) REFERENCES projetos(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes_sistema (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NULL,
    chave      VARCHAR(100) NOT NULL,
    valor      TEXT,
    UNIQUE KEY uq_cliente_chave (cliente_id, chave)
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
    "ALTER TABLE fonte_sincs ADD COLUMN IF NOT EXISTS detalhes_em  DATETIME NULL",
    "ALTER TABLE parl_parlamentares ADD COLUMN IF NOT EXISTS titular TINYINT(1) NOT NULL DEFAULT 1",
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

// ── Tabelas de sync semanal ────────────────────────────────────────────────

$pdo->exec("CREATE TABLE IF NOT EXISTS fonte_sincs (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key   VARCHAR(50) NOT NULL UNIQUE,
    status       ENUM('pendente','executando','ok','erro') NOT NULL DEFAULT 'pendente',
    iniciado_em  DATETIME NULL,
    concluido_em DATETIME NULL,
    detalhes_em  DATETIME NULL,
    total_parl   INT UNSIGNED NOT NULL DEFAULT 0,
    detalhes     TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_parlamentares (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key       VARCHAR(50)  NOT NULL,
    sapl_id          INT UNSIGNED NOT NULL,
    nome_completo    VARCHAR(300) NOT NULL DEFAULT '',
    nome_parlamentar VARCHAR(200) NOT NULL DEFAULT '',
    partido_sigla    VARCHAR(50)  NOT NULL DEFAULT '',
    uf               VARCHAR(5)   NOT NULL DEFAULT '',
    fotografia_url   VARCHAR(500) NULL,
    email            VARCHAR(200) NULL,
    ativo            TINYINT(1)   NOT NULL DEFAULT 1,
    titular          TINYINT(1)   NOT NULL DEFAULT 1,
    sincronizado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq (source_key, sapl_id),
    INDEX idx_source (source_key),
    INDEX idx_partido (source_key, partido_sigla)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_legislaturas (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key      VARCHAR(50)  NOT NULL,
    sapl_id         INT UNSIGNED NOT NULL,
    numero          INT UNSIGNED NOT NULL DEFAULT 0,
    data_inicio     DATE NULL,
    data_fim        DATE NULL,
    sincronizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq (source_key, sapl_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_partidos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key      VARCHAR(50)  NOT NULL,
    sapl_id         VARCHAR(50)  NOT NULL,
    sigla           VARCHAR(50)  NOT NULL DEFAULT '',
    nome            VARCHAR(200) NOT NULL DEFAULT '',
    sincronizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq (source_key, sapl_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_mandatos (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key      VARCHAR(50)  NOT NULL,
    parlamentar_id  INT UNSIGNED NOT NULL,
    legislatura_id  INT UNSIGNED NOT NULL,
    titular         TINYINT(1)   NOT NULL DEFAULT 1,
    votos_recebidos VARCHAR(50)  NULL,
    coligacao       VARCHAR(500) NULL,
    sincronizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq (source_key, parlamentar_id, legislatura_id),
    INDEX idx_leg (source_key, legislatura_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Tabelas estruturadas (extraídas do sapl_cache) ────────────────────────────

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_filiacoes (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key       VARCHAR(50)  NOT NULL,
    sapl_id          INT UNSIGNED NOT NULL,
    partido_sigla    VARCHAR(50)  NOT NULL DEFAULT '',
    partido_nome     VARCHAR(200) NOT NULL DEFAULT '',
    data_filiacao    DATE NULL,
    data_desfiliacao DATE NULL,
    atual            TINYINT(1)   NOT NULL DEFAULT 0,
    atualizado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parl  (source_key, sapl_id),
    INDEX idx_part  (source_key, partido_sigla)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_comissoes (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key    VARCHAR(50)  NOT NULL,
    sapl_id       INT UNSIGNED NOT NULL,
    comissao_str  VARCHAR(500) NOT NULL DEFAULT '',
    comissao_id   INT UNSIGNED NULL,
    data_inicio   DATE NULL,
    data_fim      DATE NULL,
    titular       TINYINT(1)   NOT NULL DEFAULT 0,
    atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parl (source_key, sapl_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_materias (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key        VARCHAR(50)  NOT NULL,
    sapl_id           INT UNSIGNED NOT NULL,
    materia_id        INT UNSIGNED NULL,
    tipo_sigla        VARCHAR(50)  NOT NULL DEFAULT '',
    numero            VARCHAR(50)  NOT NULL DEFAULT '',
    ano               SMALLINT UNSIGNED NULL,
    ementa            TEXT NULL,
    data_apresentacao DATE NULL,
    situacao          VARCHAR(300) NOT NULL DEFAULT '',
    descricao         VARCHAR(600) NOT NULL DEFAULT '',
    primeiro_autor    TINYINT(1)   NOT NULL DEFAULT 1,
    atualizado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parl (source_key, sapl_id),
    INDEX idx_ano  (source_key, ano)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_normas (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key    VARCHAR(50)  NOT NULL,
    sapl_id       INT UNSIGNED NOT NULL,
    norma_id      INT UNSIGNED NULL,
    tipo_sigla    VARCHAR(50)  NOT NULL DEFAULT '',
    numero        VARCHAR(50)  NOT NULL DEFAULT '',
    ano           SMALLINT UNSIGNED NULL,
    ementa        TEXT NULL,
    data_norma    DATE NULL,
    texto_integral VARCHAR(600) NULL,
    descricao     VARCHAR(600) NOT NULL DEFAULT '',
    atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parl (source_key, sapl_id),
    INDEX idx_ano  (source_key, ano)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_relatorias (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key       VARCHAR(50)  NOT NULL,
    sapl_id          INT UNSIGNED NOT NULL,
    materia_id       INT UNSIGNED NULL,
    materia_str      VARCHAR(600) NOT NULL DEFAULT '',
    comissao_str     VARCHAR(300) NOT NULL DEFAULT '',
    data_designacao  DATE NULL,
    data_destituicao DATE NULL,
    atualizado_em    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parl (source_key, sapl_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_frentes (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key    VARCHAR(50)  NOT NULL,
    sapl_id       INT UNSIGNED NOT NULL,
    frente_id     INT UNSIGNED NULL,
    frente_nome   VARCHAR(500) NOT NULL DEFAULT '',
    cargo         VARCHAR(200) NOT NULL DEFAULT '',
    ativa         TINYINT(1)   NOT NULL DEFAULT 1,
    atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parl (source_key, sapl_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_perfil_detalhe (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key           VARCHAR(50)  NOT NULL,
    sapl_id              INT UNSIGNED NOT NULL,
    situacao             VARCHAR(200) NOT NULL DEFAULT '',
    biografia            TEXT NULL,
    data_nascimento      DATE NULL,
    municipio_nascimento VARCHAR(200) NULL,
    uf_nascimento        VARCHAR(5)   NULL,
    escolaridade         VARCHAR(200) NULL,
    profissao            VARCHAR(200) NULL,
    homepage             VARCHAR(500) NULL,
    gabinete             VARCHAR(200) NULL,
    telefone             VARCHAR(100) NULL,
    atualizado_em        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq (source_key, sapl_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "[migrate] Tabelas de sync semanal criadas.\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS agente_historico (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    projeto_id   INT UNSIGNED NOT NULL,
    usuario_id   INT UNSIGNED NOT NULL,
    contexto     VARCHAR(30)  NOT NULL DEFAULT 'sentinela',
    contexto_id  VARCHAR(50)  NULL,
    historico    JSON         NOT NULL,
    atualizado_em DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hist (projeto_id, usuario_id, contexto, contexto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "[migrate] Tabela agente_historico criada.\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_materias_detalhe (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key        VARCHAR(50)   NOT NULL,
    materia_id        INT UNSIGNED  NOT NULL,
    tipo_sigla        VARCHAR(50)   NOT NULL DEFAULT '',
    tipo_descricao    VARCHAR(200)  NOT NULL DEFAULT '',
    numero            VARCHAR(50)   NOT NULL DEFAULT '',
    ano               SMALLINT UNSIGNED NULL,
    ementa            TEXT NULL,
    data_apresentacao DATE NULL,
    situacao          VARCHAR(300)  NOT NULL DEFAULT '',
    orgao_atual       VARCHAR(100)  NOT NULL DEFAULT '',
    regime_tramitacao VARCHAR(200)  NOT NULL DEFAULT '',
    despacho_atual    TEXT NULL,
    palavras_chave    TEXT NULL,
    em_tramitacao     TINYINT(1)    NOT NULL DEFAULT 1,
    texto_url         VARCHAR(600)  NULL,
    descricao         VARCHAR(600)  NOT NULL DEFAULT '',
    atualizado_em     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq (source_key, materia_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_materias_tramitacao (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key        VARCHAR(50)   NOT NULL,
    materia_id        INT UNSIGNED  NOT NULL,
    sequencia         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    data_tramitacao   DATE NULL,
    status_str        VARCHAR(500)  NOT NULL DEFAULT '',
    destino_str       VARCHAR(300)  NOT NULL DEFAULT '',
    texto             TEXT NULL,
    atualizado_em     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mat (source_key, materia_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "[migrate] Tabelas parl_materias_detalhe e parl_materias_tramitacao criadas.\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_emendas (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key       VARCHAR(50)   NOT NULL,
    parlamentar_id   INT UNSIGNED  NOT NULL,
    emenda_cod       VARCHAR(100)  NOT NULL DEFAULT '',
    numero           VARCHAR(100)  NOT NULL DEFAULT '',
    ano              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tipo             VARCHAR(200)  NOT NULL DEFAULT '',
    localidade       VARCHAR(300)  NOT NULL DEFAULT '',
    funcao           VARCHAR(200)  NOT NULL DEFAULT '',
    subfuncao        VARCHAR(200)  NOT NULL DEFAULT '',
    valor_dotacao    DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    valor_empenhado  DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    valor_pago       DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    descricao        VARCHAR(600)  NOT NULL DEFAULT '',
    atualizado_em    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parl_ano (source_key, parlamentar_id, ano)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "[migrate] Tabela parl_emendas criada.\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_materias_autores (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key        VARCHAR(50)   NOT NULL,
    materia_id        INT UNSIGNED  NOT NULL,
    nome_autor        VARCHAR(300)  NOT NULL DEFAULT '',
    tipo_autor        VARCHAR(100)  NOT NULL DEFAULT '',
    id_deputado_autor INT UNSIGNED  NULL,
    sigla_partido     VARCHAR(50)   NOT NULL DEFAULT '',
    sigla_uf          VARCHAR(5)    NOT NULL DEFAULT '',
    ordem_assinatura  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    proponente        TINYINT(1)    NOT NULL DEFAULT 0,
    atualizado_em     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mat (source_key, materia_id),
    INDEX idx_dep (source_key, id_deputado_autor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_materias_temas (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key    VARCHAR(50)   NOT NULL,
    materia_id    INT UNSIGNED  NOT NULL,
    cod_tema      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    tema          VARCHAR(200)  NOT NULL DEFAULT '',
    relevancia    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    atualizado_em DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mat  (source_key, materia_id),
    INDEX idx_tema (source_key, cod_tema)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "[migrate] Tabelas parl_materias_autores e parl_materias_temas criadas.\n";

// Adiciona colunas url e regime à tramitação (migrations)
$migracoes2 = [
    // Sync semanal — compatibilidade com bancos já existentes
    "ALTER TABLE fonte_sincs ADD COLUMN IF NOT EXISTS detalhes_em DATETIME NULL AFTER concluido_em",
    "ALTER TABLE parl_parlamentares ADD COLUMN IF NOT EXISTS titular TINYINT(1) NOT NULL DEFAULT 1 AFTER ativo",
    "ALTER TABLE parl_mandatos ADD COLUMN IF NOT EXISTS votos_recebidos VARCHAR(50) NULL AFTER titular",
    "ALTER TABLE parl_mandatos ADD COLUMN IF NOT EXISTS coligacao VARCHAR(500) NULL AFTER votos_recebidos",
    "ALTER TABLE parl_comissoes ADD COLUMN IF NOT EXISTS comissao_id INT UNSIGNED NULL AFTER comissao_str",
    "ALTER TABLE parl_materias_tramitacao ADD COLUMN IF NOT EXISTS url    VARCHAR(600) NULL AFTER texto",
    "ALTER TABLE parl_materias_tramitacao ADD COLUMN IF NOT EXISTS regime VARCHAR(200) NOT NULL DEFAULT '' AFTER destino_str",
    // Emendas — enriquecimento com órgão, ação, programa e valor liquidado
    "ALTER TABLE parl_emendas ADD COLUMN IF NOT EXISTS orgao           VARCHAR(300) NOT NULL DEFAULT '' AFTER subfuncao",
    "ALTER TABLE parl_emendas ADD COLUMN IF NOT EXISTS acao            VARCHAR(300) NOT NULL DEFAULT '' AFTER orgao",
    "ALTER TABLE parl_emendas ADD COLUMN IF NOT EXISTS programa        VARCHAR(300) NOT NULL DEFAULT '' AFTER acao",
    "ALTER TABLE parl_emendas ADD COLUMN IF NOT EXISTS valor_liquidado DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER valor_empenhado",
    // Usuários — tokens de redefinição de senha
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_token      VARCHAR(128) NULL DEFAULT NULL",
    "ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_expires_at DATETIME NULL DEFAULT NULL",
];
foreach ($migracoes2 as $sql) {
    try { $pdo->exec($sql); } catch (PDOException $e) { /* já existe */ }
}
echo "[migrate] Colunas url/regime/emendas adicionadas.\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS parl_extras (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key    VARCHAR(50)  NOT NULL,
    sapl_id       INT UNSIGNED NOT NULL,
    aba           VARCHAR(50)  NOT NULL COMMENT 'inicio|materias|normas|emendas|comissoes|frentes|filiacoes|relatorias',
    titulo        VARCHAR(300) NOT NULL DEFAULT '',
    dados_json    JSON         NOT NULL,
    criado_por    INT UNSIGNED NULL,
    criado_em     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_parl (source_key, sapl_id, aba),
    FOREIGN KEY (criado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

echo "[migrate] Tabela parl_extras criada.\n";

echo "[migrate] Concluído.\n";
