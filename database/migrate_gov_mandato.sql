-- Migração: dados de mandato/eleição para governadores (TSE consulta_cand)
-- Executar: php database/migrate_gov_mandato.php

ALTER TABLE parl_perfil_detalhe
  ADD COLUMN IF NOT EXISTS votos_2022     BIGINT UNSIGNED NULL AFTER patrimonio,
  ADD COLUMN IF NOT EXISTS coligacao_2022 VARCHAR(300)    NULL AFTER votos_2022,
  ADD COLUMN IF NOT EXISTS resultado_2022 VARCHAR(100)    NULL AFTER coligacao_2022,
  ADD COLUMN IF NOT EXISTS turno_2022     TINYINT UNSIGNED NULL AFTER resultado_2022;
