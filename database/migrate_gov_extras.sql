-- Migração: dados extras de governadores (TSE)
-- Executar: php database/migrate_gov_extras.php

ALTER TABLE parl_parlamentares
  ADD COLUMN tse_sq BIGINT UNSIGNED NULL AFTER partido_sigla;

ALTER TABLE parl_perfil_detalhe
  ADD COLUMN patrimonio DECIMAL(15,2) NULL AFTER escolaridade;

CREATE TABLE IF NOT EXISTS parl_redes_sociais (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_key  VARCHAR(50)  NOT NULL,
  sapl_id     INT UNSIGNED NOT NULL,
  plataforma  VARCHAR(50)  NOT NULL,
  url         VARCHAR(500) NOT NULL,
  UNIQUE KEY uq_parl_rede (source_key, sapl_id, plataforma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
