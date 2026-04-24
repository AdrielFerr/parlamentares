<?php
// Lê de env var → Docker secret → default (nessa ordem de prioridade)
function _env(string $key, string $default = ''): string {
    $val = getenv($key);
    if ($val !== false && $val !== '') return $val;
    $secret = '/run/secrets/' . strtolower($key);
    if (file_exists($secret)) return trim(file_get_contents($secret));
    return $default;
}

// Database
define('DB_HOST',    _env('DB_HOST',    'localhost'));
define('DB_NAME',    _env('DB_NAME',    'keekconecta'));
define('DB_USER',    _env('DB_USER',    'root'));
define('DB_PASS',    _env('DB_PASS',    ''));
define('DB_CHARSET', _env('DB_CHARSET', 'utf8mb4'));

// Encryption key for API keys (32 bytes hex-encoded — troque em produção!)
define('CRYPTO_KEY', _env('CRYPTO_KEY', 'a1b2c3d4e5f6789012345678901234567890abcdef1234567890abcdef123456'));

// App
define('APP_NAME',  _env('APP_NAME',  'KeekConecta'));
define('APP_URL',   _env('APP_URL',   'http://localhost'));
define('BASE_PATH', _env('BASE_PATH', ''));

// Fontes legislativas
define('SOURCES', [
    'cmjp'           => ['label' => 'C.M. João Pessoa',    'url' => 'https://sapl.joaopessoa.pb.leg.br'],
    'bayeux'         => ['label' => 'C.M. Bayeux',         'url' => 'https://sapl.bayeux.pb.leg.br'],
    'cabedelo'       => ['label' => 'C.M. Cabedelo',       'url' => 'https://sapl.cabedelo.pb.leg.br'],
    'campina'        => ['label' => 'C.M. Campina Grande',  'url' => 'https://sapl.campinagrande.pb.leg.br'],
    'santarita'      => ['label' => 'C.M. Santa Rita',     'url' => 'https://sapl.santarita.pb.leg.br'],
    'alpb'           => ['label' => 'ALPB',                 'url' => 'https://sapl.al.pb.leg.br'],
    'alsp'           => ['label' => 'ALESP',                'url' => 'https://sapl.al.sp.leg.br'],
    'alrj'           => ['label' => 'ALERJ',                'url' => 'https://sapl.alerj.rj.gov.br'],
    'brasilia'       => ['label' => 'C.M. Brasília',        'url' => 'https://sapl.cl.df.leg.br'],
    'alpe'           => ['label' => 'ALEPE',                'url' => 'https://sapl.alepe.pe.leg.br'],
    'alrn'           => ['label' => 'ALERN',                'url' => 'https://sapl.al.rn.leg.br'],
    'alce'           => ['label' => 'ALECE',                'url' => 'https://sapl.al.ce.leg.br'],
    'alba'           => ['label' => 'ALBA',                 'url' => 'https://sapl.al.ba.leg.br'],
    'alsc'           => ['label' => 'ALESC',                'url' => 'https://sapl.alesc.sc.leg.br'],
    'almg'           => ['label' => 'ALMG',                 'url' => 'https://sapl.almg.gov.br'],
    'camara_federal' => ['label' => 'Câmara Federal',       'url' => 'https://dadosabertos.camara.leg.br'],
    'senado'         => ['label' => 'Senado Federal',       'url' => 'https://legis.senado.leg.br'],
]);

define('DEFAULT_SOURCE', 'cmjp');
