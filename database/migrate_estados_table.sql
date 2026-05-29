-- Tabela de estados brasileiros
CREATE TABLE IF NOT EXISTS estados (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    uf      CHAR(2)      NOT NULL UNIQUE,
    nome    VARCHAR(100) NOT NULL,
    regiao  CHAR(2)      NOT NULL COMMENT 'N, NE, CO, SE, S',
    ativo   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO estados (uf, nome, regiao) VALUES
('AC', 'Acre',                'N'),
('AL', 'Alagoas',             'NE'),
('AM', 'Amazonas',            'N'),
('AP', 'Amapá',               'N'),
('BA', 'Bahia',               'NE'),
('CE', 'Ceará',               'NE'),
('DF', 'Distrito Federal',    'CO'),
('ES', 'Espírito Santo',      'SE'),
('GO', 'Goiás',               'CO'),
('MA', 'Maranhão',            'NE'),
('MG', 'Minas Gerais',        'SE'),
('MS', 'Mato Grosso do Sul',  'CO'),
('MT', 'Mato Grosso',         'CO'),
('PA', 'Pará',                'N'),
('PB', 'Paraíba',             'NE'),
('PE', 'Pernambuco',          'NE'),
('PI', 'Piauí',               'NE'),
('PR', 'Paraná',              'S'),
('RJ', 'Rio de Janeiro',      'SE'),
('RN', 'Rio Grande do Norte', 'NE'),
('RO', 'Rondônia',            'N'),
('RR', 'Roraima',             'N'),
('RS', 'Rio Grande do Sul',   'S'),
('SC', 'Santa Catarina',      'S'),
('SE', 'Sergipe',             'NE'),
('SP', 'São Paulo',           'SE'),
('TO', 'Tocantins',           'N')
ON DUPLICATE KEY UPDATE nome = VALUES(nome), regiao = VALUES(regiao);

-- Torna fonte_id opcional nos projetos (lógica de parlamentares será revisada)
ALTER TABLE projetos
  MODIFY COLUMN fonte_id INT NULL DEFAULT NULL;
