<?php
define('ROOT', __DIR__);
define('APP',  ROOT . '/app');

require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';
require APP  . '/Core/Crypto.php';
require APP  . '/Core/Model.php';
require APP  . '/Core/Auth.php';
require APP  . '/Core/View.php';
require APP  . '/Core/Controller.php';
require APP  . '/Core/Router.php';
require APP  . '/Core/SaplApi.php';

// Models
require APP . '/Models/Usuario.php';
require APP . '/Models/Cliente.php';
require APP . '/Models/Projeto.php';
require APP . '/Models/FonteLegislativa.php';
require APP . '/Models/SentinelaConversa.php';
require APP . '/Models/SentinelaArquivo.php';
require APP . '/Models/Parlamentar.php';
require APP . '/Models/Configuracao.php';
require APP . '/Models/SaplCache.php';

// Controllers
require APP . '/Controllers/AuthController.php';
require APP . '/Controllers/ProjetosController.php';
require APP . '/Controllers/ParlamentaresController.php';
require APP . '/Controllers/SentinelaController.php';
require APP . '/Controllers/ApiController.php';
require APP . '/Controllers/AdminController.php';
require APP . '/Controllers/DashboardController.php';

session_start();

$router = new Router();

// Auth
$router->add('GET',  '/login',   'AuthController', 'loginForm');
$router->add('POST', '/login',   'AuthController', 'login');
$router->add('GET',  '/logout',  'AuthController', 'logout');

// Projetos
$router->add('GET',  '/projetos',              'ProjetosController', 'index');
$router->add('POST', '/projetos/selecionar',   'ProjetosController', 'selecionar');
$router->add('GET',  '/projetos/dados',        'ProjetosController', 'dados');
$router->add('POST', '/projetos/ajax/criar',   'ProjetosController', 'ajaxCriar');
$router->add('POST', '/projetos/ajax/editar',  'ProjetosController', 'ajaxEditar');
$router->add('GET',  '/projetos/novo',         'ProjetosController', 'form');
$router->add('POST', '/projetos/novo',         'ProjetosController', 'store');
$router->add('GET',  '/projetos/editar',       'ProjetosController', 'edit');
$router->add('POST', '/projetos/editar',       'ProjetosController', 'update');
$router->add('POST', '/projetos/deletar',      'ProjetosController', 'destroy');

// Parlamentares
$router->add('GET', '/parlamentares', 'ParlamentaresController', 'index');

// Sentinela
$router->add('GET', '/sentinela', 'SentinelaController', 'index');

// Admin
$router->add('GET',  '/admin',               'AdminController', 'index');
$router->add('GET',  '/admin/usuarios',      'AdminController', 'usuarios');
$router->add('GET',  '/admin/usuarios/novo', 'AdminController', 'usuarioForm');
$router->add('POST', '/admin/usuarios/novo', 'AdminController', 'usuarioStore');
$router->add('POST', '/admin/usuarios/deletar', 'AdminController', 'usuarioDestroy');
$router->add('POST', '/admin/usuarios/senha',   'AdminController', 'usuarioSenha');
$router->add('POST', '/admin/clientes/ajax',  'AdminController', 'clienteAjaxCreate');
$router->add('GET',  '/admin/clientes',      'AdminController', 'clientes');
$router->add('GET',  '/admin/clientes/novo', 'AdminController', 'clienteForm');
$router->add('POST', '/admin/clientes/novo', 'AdminController', 'clienteStore');
$router->add('POST', '/admin/clientes/deletar', 'AdminController', 'clienteDestroy');

// Aparência (SuperAdmin)
$router->add('GET',  '/admin/aparencia',             'AdminController', 'aparencia');
$router->add('POST', '/admin/aparencia',             'AdminController', 'aparenciaSave');
$router->add('POST', '/admin/aparencia/logo-remover','AdminController', 'aparenciaLogoRemove');

// Perfil
$router->add('GET',  '/perfil',        'AdminController', 'perfilForm');
$router->add('POST', '/perfil',        'AdminController', 'perfilStore');
$router->add('POST', '/perfil/json',   'AdminController', 'perfilJson');

// Dashboard embed
$router->add('GET', '/dashboard', 'DashboardController', 'visualizar');

// API (AJAX)
$router->add('GET',  '/api/proxy',    'ApiController', 'proxy');
$router->add('GET',  '/api/img',      'ApiController', 'img');
$router->add('POST', '/api/openai',   'ApiController', 'openai');
$router->add('GET',  '/api/sources',  'ApiController', 'sources');
$router->add('POST', '/api/arquivo',       'ApiController', 'arquivoStore');
$router->add('POST', '/api/arquivo/remover', 'ApiController', 'arquivoRemove');
$router->add('POST', '/api/parl-count',    'ApiController', 'updateParlTotal');
$router->add('POST', '/api/cache/invalidar',   'ApiController', 'cacheInvalidar');
$router->add('GET',  '/api/cache/status',      'ApiController', 'cacheStatus');
$router->add('GET',  '/api/bulk',              'ApiController', 'bulk');
$router->add('GET',  '/api/cache/sincronizar', 'ApiController', 'sincronizar');

$router->dispatch();
