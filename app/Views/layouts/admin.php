<?php
$userNome  = Auth::user()['nome']  ?? '';
$userEmail = Auth::user()['email'] ?? '';
$userNivel = Auth::nivel();

// Branding dinâmico
$_ctxIdForBranding = ($ctx['id'] ?? null);
$_cssVars  = Configuracao::getCssVars($_ctxIdForBranding);
$_logoUrl  = Configuracao::logoUrl($_ctxIdForBranding);

$lvls      = ['SuperAdmin','Administrador','Gestor','Analista','Visualizador'];
$userRole  = $lvls[$userNivel] ?? 'Usuário';
$partes    = array_filter(explode(' ', trim($userNome)));
$iniciais  = strtoupper(substr($partes[0] ?? 'U', 0, 1) . substr(end($partes) ?? '', 0, 1));
$paleta    = ['#4f46e5','#0891b2','#7c3aed','#ea580c','#16a34a','#db2777','#ca8a04'];
$corAvatar = $paleta[abs(crc32($userNome)) % count($paleta)];

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = rtrim(BASE_PATH, '/');
if ($base && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));

$isHub = ($uri === '/admin' || $uri === '/admin/');

// Mapa de breadcrumb
$breadcrumbMap = [
    '/admin/usuarios'      => ['label' => 'Membros da Equipe', 'up' => '/admin'],
    '/admin/usuarios/novo' => ['label' => 'Novo Usuário',      'up' => '/admin/usuarios'],
    '/admin/clientes'      => ['label' => 'Clientes',          'up' => '/admin'],
    '/admin/clientes/novo' => ['label' => 'Novo Cliente',      'up' => '/admin/clientes'],
    '/admin/aparencia'     => ['label' => 'Aparência',         'up' => '/admin'],
    '/perfil'              => ['label' => 'Meu Perfil',        'up' => '/admin'],
];
$breadcrumb = null;
foreach ($breadcrumbMap as $path => $info) {
    if ($uri === $path || str_starts_with($uri, $path . '/')) {
        $breadcrumb = $info;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Configurações — <?= htmlspecialchars(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@800&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
a{text-decoration:none;color:inherit}
button{font-family:inherit;cursor:pointer}
:root{
  --bg:#f0f2f8;--card:#fff;--accent:#16a34a;--accent-light:#f0fdf4;--accent-dark:#15803d;
  --text:#111827;--muted:#6b7280;--border:#e5e7eb;
  --red:#dc2626;--red-light:#fef2f2;
  <?= $_cssVars ?>
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column}

/* ── TOPBAR ── */
.cfg-topbar{background:#fff;border-bottom:1px solid var(--border);height:56px;padding:0 32px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;position:sticky;top:0;z-index:100}
.cfg-topbar-brand{display:flex;align-items:center;gap:12px}
.cfg-logo-img{max-height:36px;max-width:150px;object-fit:contain}
.cfg-logo-icon{width:32px;height:32px;background:var(--accent);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:15px}
.cfg-brand-name{font-size:15px;font-weight:700;color:var(--text);letter-spacing:-.2px}
.cfg-topbar-right{display:flex;align-items:center;gap:16px}
.cfg-user{position:relative;display:flex;align-items:center;gap:10px;cursor:pointer;padding:5px 8px;border-radius:10px;transition:background .15s;user-select:none}
.cfg-user:hover{background:#f3f4f6}
.cfg-avatar{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0}
.cfg-user-info{text-align:right}
.cfg-user-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.3}
.cfg-user-email{font-size:11px;color:var(--muted)}
.cfg-user-caret{color:#9ca3af;font-size:11px;transition:transform .2s}
.cfg-user.open .cfg-user-caret{transform:rotate(180deg)}
/* Dropdown */
.cfg-dropdown{position:absolute;top:calc(100% + 8px);right:0;background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 28px rgba(0,0,0,.12);min-width:210px;padding:6px;display:none;z-index:400}
.cfg-dropdown.open{display:block}
.cfg-dd-head{padding:12px 14px 10px;border-bottom:1px solid #f3f4f6;margin-bottom:4px;text-align:center}
.cfg-dd-av{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:#fff;margin:0 auto 8px}
.cfg-dd-name{font-size:14px;font-weight:700;color:var(--text)}
.cfg-dd-role{font-size:11px;color:var(--muted);margin-top:2px}
.cfg-dd-item{display:flex;align-items:center;gap:10px;padding:8px 12px;font-size:13px;font-weight:500;color:#374151;border-radius:8px;transition:background .12s;cursor:pointer;border:none;background:none;width:100%;text-align:left;text-decoration:none}
.cfg-dd-item:hover{background:#f3f4f6}
.cfg-dd-item.danger{color:var(--red)}
.cfg-dd-item.danger:hover{background:var(--red-light)}
.cfg-dd-sep{border:none;border-top:1px solid #f3f4f6;margin:4px 0}

/* ── BREADCRUMB BAR ── */
.cfg-breadbar{background:#fff;border-bottom:1px solid var(--border);padding:0 32px;height:44px;display:flex;align-items:center;justify-content:space-between}
.cfg-breadcrumb{display:flex;align-items:center;gap:6px;font-size:13px}
.cfg-breadcrumb a{color:var(--accent);font-weight:500;transition:opacity .15s}
.cfg-breadcrumb a:hover{opacity:.75}
.cfg-breadcrumb-sep{color:#d1d5db;font-size:12px}
.cfg-breadcrumb-cur{color:var(--text);font-weight:600}
.btn-voltar{display:inline-flex;align-items:center;gap:7px;padding:6px 14px;background:#fff;border:1.5px solid var(--border);border-radius:8px;font-size:13px;font-weight:600;color:var(--text);transition:all .15s}
.btn-voltar:hover{border-color:var(--accent);color:var(--accent)}
.btn-voltar i{font-size:15px}

/* ── CONTENT ── */
.cfg-wrap{flex:1;padding:36px 32px;max-width:1100px;width:100%;margin:0 auto}
@media(max-width:768px){.cfg-wrap{padding:20px 16px}.cfg-topbar{padding:0 16px}.cfg-breadbar{padding:0 16px}}

/* ── HUB HEADER ── */
.cfg-hub-head{margin-bottom:32px}
.cfg-hub-title{font-size:26px;font-weight:800;color:var(--text);margin-bottom:4px}
.cfg-hub-sub{font-size:14px;color:var(--muted)}

/* ── CARDS HUB ── */
.hub-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;max-width:860px}
@media(max-width:600px){.hub-grid{grid-template-columns:1fr}}
.hub-card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:28px 26px;display:flex;align-items:flex-start;gap:20px;text-decoration:none;color:var(--text);transition:box-shadow .2s,transform .2s,border-color .2s;cursor:pointer}
.hub-card:hover{box-shadow:0 8px 32px rgba(0,0,0,.10);transform:translateY(-3px);border-color:transparent}
.hub-card-icon{width:54px;height:54px;border-radius:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:24px}
.hub-card-body{flex:1;min-width:0}
.hub-card-title{font-size:17px;font-weight:700;color:var(--text);margin-bottom:6px;line-height:1.2}
.hub-card-desc{font-size:13px;color:var(--muted);line-height:1.6}

/* ── INNER PAGES ── */
.page-header{margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.page-title{font-family:'Playfair Display',serif;font-size:24px;font-weight:800;line-height:1.1}
.btn-primary{padding:9px 18px;background:var(--accent);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;transition:background .15s}
.btn-primary:hover{background:var(--accent-dark)}
.btn-sm{padding:7px 13px;font-size:13px;font-weight:500;font-family:inherit;border-radius:8px;cursor:pointer;border:1.5px solid var(--border);background:var(--card);color:var(--text);transition:all .15s}
.btn-sm:hover{border-color:var(--accent);color:var(--accent)}
.btn-danger{padding:7px 13px;background:transparent;color:var(--red);border:1.5px solid #fecaca;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer}
.btn-danger:hover{background:var(--red-light)}
table{width:100%;border-collapse:separate;border-spacing:0;font-size:14px}
.table-wrap{overflow-x:auto;border-radius:12px;border:1px solid var(--border);background:var(--card)}
th{text-align:left;padding:11px 14px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--accent);border-bottom:2px solid var(--border);background:var(--accent-light)}
td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:nth-child(even) td{background:#fafafa}
.badge{display:inline-block;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700}
.badge-green{background:var(--accent-light);color:var(--accent)}
.badge-gray{background:#f3f4f6;color:var(--muted)}
.empty-state{text-align:center;padding:60px 20px;color:var(--muted);font-size:14px}
label{display:block;font-size:13px;font-weight:600;margin-bottom:6px}
input[type=text],input[type=email],input[type=password],select.form-select,textarea{width:100%;padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;font-family:inherit;outline:none;margin-bottom:4px;background:#fafafa}
input:focus,select.form-select:focus,textarea:focus{border-color:var(--accent);background:#fff}
.form-group{margin-bottom:16px}
.form-hint{font-size:12px;color:var(--muted);margin-top:4px}
.error-msg{background:var(--red-light);color:var(--red);border:1px solid #fecaca;border-radius:8px;padding:9px 13px;font-size:13px;margin-bottom:16px}
.mem-table-wrap{overflow-x:auto;border-radius:12px;border:1px solid var(--border);background:#fff}
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="cfg-topbar">
  <div class="cfg-topbar-brand">
    <?php if ($_logoUrl): ?>
      <img src="<?= BASE_PATH . htmlspecialchars($_logoUrl) ?>" alt="Logo" class="cfg-logo-img">
    <?php else: ?>
      <div class="cfg-logo-icon">K</div>
      <span class="cfg-brand-name"><?= htmlspecialchars(APP_NAME) ?></span>
    <?php endif; ?>
  </div>
  <div class="cfg-topbar-right">
    <div class="cfg-user" id="cfgUserBtn" onclick="toggleCfgDropdown()">
      <div class="cfg-user-info">
        <div class="cfg-user-name"><?= htmlspecialchars($userNome) ?></div>
        <div class="cfg-user-email"><?= htmlspecialchars($userEmail) ?></div>
      </div>
      <div class="cfg-avatar" style="background:<?= $corAvatar ?>"><?= htmlspecialchars($iniciais) ?></div>
      <i class="ph ph-caret-down cfg-user-caret"></i>

      <div class="cfg-dropdown" id="cfgDropdown">
        <div class="cfg-dd-head">
          <div class="cfg-dd-av" style="background:<?= $corAvatar ?>"><?= htmlspecialchars($iniciais) ?></div>
          <div class="cfg-dd-name"><?= htmlspecialchars($userNome) ?></div>
          <div class="cfg-dd-role"><?= htmlspecialchars($userRole) ?></div>
        </div>
        <a href="<?= BASE_PATH ?>/projetos" style="display:flex;justify-content:center;width:100%;margin-top:10px;padding:7px 12px;background:#f3f4f6;border:none;border-radius:7px;font-size:13px;font-weight:600;color:var(--text);text-align:center;transition:background .15s" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">
          Voltar aos projetos
        </a>
        <hr class="cfg-dd-sep">
        <a href="<?= BASE_PATH ?>/logout" class="cfg-dd-item danger">
          <i class="ph ph-sign-out" style="font-size:15px"></i> Sair
        </a>
      </div>
    </div>
  </div>
</header>

<script>
function toggleCfgDropdown() {
  const btn = document.getElementById('cfgUserBtn');
  const dd  = document.getElementById('cfgDropdown');
  btn.classList.toggle('open');
  dd.classList.toggle('open');
}
document.addEventListener('click', function(e) {
  const btn = document.getElementById('cfgUserBtn');
  if (btn && !btn.contains(e.target)) {
    btn.classList.remove('open');
    document.getElementById('cfgDropdown').classList.remove('open');
  }
});
</script>

<!-- BREADCRUMB (só em sub-páginas) -->
<?php if ($breadcrumb): ?>
<div class="cfg-breadbar">
  <nav class="cfg-breadcrumb">
    <a href="<?= BASE_PATH ?>/admin">Configurações</a>
    <span class="cfg-breadcrumb-sep"><i class="ph ph-caret-right"></i></span>
    <span class="cfg-breadcrumb-cur"><?= htmlspecialchars($breadcrumb['label']) ?></span>
  </nav>
  <a href="<?= BASE_PATH . $breadcrumb['up'] ?>" class="btn-voltar">
    <i class="ph ph-arrow-u-up-left"></i> Voltar
  </a>
</div>
<?php endif; ?>

<!-- CONTEÚDO -->
<div class="cfg-wrap">
  <?= $content ?>
</div>

</body>
</html>
