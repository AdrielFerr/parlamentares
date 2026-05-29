-- Adiciona coluna UF aos projetos (indica qual estado o projeto representa)
ALTER TABLE projetos
  ADD COLUMN uf VARCHAR(2) NOT NULL DEFAULT '' AFTER fonte_id;

-- Auto-preenche UF para fontes estaduais/municipais conhecidas
UPDATE projetos p
INNER JOIN fontes_legislativas f ON f.id = p.fonte_id
SET p.uf = CASE f.source_key
  WHEN 'alpb'      THEN 'PB'
  WHEN 'cmjp'      THEN 'PB'
  WHEN 'campina'   THEN 'PB'
  WHEN 'bayeux'    THEN 'PB'
  WHEN 'cabedelo'  THEN 'PB'
  WHEN 'santarita' THEN 'PB'
  ELSE ''
END
WHERE f.source_key IN ('alpb','cmjp','campina','bayeux','cabedelo','santarita');

-- Para projetos com fonte camara_federal ou senado, defina a UF manualmente
-- depois de rodar esta migração, via tela de edição de projetos.
