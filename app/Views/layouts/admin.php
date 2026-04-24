<?php
$userNome  = Auth::user()['nome']  ?? '';
$userEmail = Auth::user()['email'] ?? '';
$userNivel = Auth::nivel();
$lvls      = ['SuperAdmin','Administrador','Gestor','Analista','Visualizador'];
$userRole  = $lvls[$userNivel] ?? 'Usuário';
$partes    = array_filter(explode(' ', trim($userNome)));
$iniciais  = strtoupper(substr($partes[0] ?? 'U', 0, 1) . substr(end($partes) ?? '', 0, 1));
$paleta    = ['#16a34a','#2563eb','#9333ea','#ea580c','#0891b2','#db2777','#ca8a04'];
$corAvatar = $paleta[abs(crc32($userNome)) % count($paleta)];

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = rtrim(BASE_PATH, '/');
if ($base && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));
function adminActive(string $path, string $uri): string {
    return $uri === $path || str_starts_with($uri, $path.'/') ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Configurações — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@800&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
a{text-decoration:none;color:inherit}
button{font-family:inherit;cursor:pointer}
:root{
  --bg:#f6f6f6;--card:#fff;--accent:#16a34a;--accent-light:#f0fdf4;--accent-dark:#15803d;
  --text:#111827;--muted:#6b7280;--border:#e5e7eb;--sidebar-w:220px;
  --red:#dc2626;--red-light:#fef2f2;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex}

/* SIDEBAR */
.sidebar{width:var(--sidebar-w);background:#fff;border-right:1px solid var(--border);display:flex;flex-direction:column;min-height:100vh;flex-shrink:0;position:fixed;top:0;left:0;bottom:0;z-index:100}
.sidebar-logo{display:flex;align-items:center;gap:10px;padding:18px 16px 16px;border-bottom:1px solid var(--border)}
.logo-icon{width:30px;height:30px;background:var(--accent);border-radius:7px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
.logo-text{font-size:15px;font-weight:700;color:var(--text);letter-spacing:-.3px}
.sidebar-nav{flex:1;padding:10px 0;overflow-y:auto}
.nav-section{font-size:10px;font-weight:600;letter-spacing:.07em;color:#9ca3af;text-transform:uppercase;padding:14px 16px 5px}
.nav-item{display:flex;align-items:center;gap:9px;padding:8px 16px;font-size:13px;font-weight:500;color:#4b5563;border-left:3px solid transparent;transition:all .12s}
.nav-item:hover{color:var(--text);background:#f9fafb}
.nav-item.active{color:var(--accent);background:var(--accent-light);border-left-color:var(--accent);font-weight:600}
.nav-icon{font-size:18px;width:20px;text-align:center;flex-shrink:0;display:flex;align-items:center;justify-content:center}
.sidebar-footer{border-top:1px solid var(--border);padding:12px 14px;flex-shrink:0}
.sf-user{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.sf-avatar{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;flex-shrink:0}
.sf-name{font-size:12px;font-weight:600;color:var(--text);line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sf-role{font-size:10px;color:var(--muted)}
.sf-link{font-size:11px;color:var(--red);transition:color .12s;display:inline-flex;align-items:center;gap:4px}
.sf-link:hover{color:#b91c1c}

/* MAIN */
.main-wrap{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{background:#fff;border-bottom:1px solid var(--border);height:52px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar-title{font-size:14px;font-weight:600;color:var(--muted)}
.topbar-user{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text)}
.topbar-avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff}
.main-content{flex:1;padding:28px 32px}

/* COMPONENTES */
.page-header{margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.page-title{font-family:'Playfair Display',serif;font-size:24px;font-weight:800;line-height:1.1}
.btn-primary{padding:9px 18px;background:var(--accent);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;transition:background .15s}
.btn-primary:hover{background:var(--accent-dark)}
.btn-sm{padding:7px 13px;font-size:13px;font-weight:500;font-family:inherit;border-radius:8px;cursor:pointer;border:1.5px solid var(--border);background:var(--card);color:var(--text);transition:all .15s}
.btn-sm:hover{border-color:var(--accent);color:var(--accent)}
.btn-danger{padding:7px 13px;background:transparent;color:var(--red);border:1.5px solid #fecaca;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer}
.btn-danger:hover{background:var(--red-light)}
table{width:100%;border-collapse:separate;border-spacing:0;font-size:14px}
.table-wrap{overflow-x:auto;border-radius:10px;border:1px solid var(--border);background:var(--card)}
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
.cfg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;max-width:700px}
.cfg-card{display:flex;align-items:center;gap:20px;background:#fff;border:1px solid var(--border);border-radius:14px;padding:22px 20px;text-decoration:none;color:var(--text);transition:box-shadow .2s,border-color .2s,transform .2s}
.cfg-card:hover{border-color:var(--accent);box-shadow:0 8px 24px rgba(26,107,79,0.09);transform:translateY(-2px)}
.cfg-icon{font-size:28px;flex-shrink:0;width:50px;height:50px;background:var(--accent-light);border-radius:12px;display:flex;align-items:center;justify-content:center}
.cfg-body{flex:1;min-width:0}
.cfg-title{font-size:15px;font-weight:700;margin-bottom:5px;color:var(--text)}
.cfg-desc{font-size:13px;color:var(--muted);line-height:1.5}
.cfg-arrow{color:var(--muted);flex-shrink:0;transition:color .2s,transform .2s}
.cfg-card:hover .cfg-arrow{color:var(--accent);transform:translateX(3px)}
.mem-table-wrap{overflow-x:auto;border-radius:12px;border:1px solid var(--border);background:#fff}
@media(max-width:768px){.main-wrap{margin-left:0}.main-content{padding:16px}}
</style>
</head>
<body>

<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">K</div>
    <span class="logo-text"><?= htmlspecialchars(APP_NAME) ?></span>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section">Configurações</div>

    <a href="<?= BASE_PATH ?>/admin/usuarios" class="nav-item <?= adminActive('/admin/usuarios', $uri) ?>">
      <span class="nav-icon"><i class="ph ph-users"></i></span> Membros da Equipe
    </a>

    <a href="<?= BASE_PATH ?>/perfil" class="nav-item <?= adminActive('/perfil', $uri) ?>">
      <span class="nav-icon"><i class="ph ph-user-circle"></i></span> Meu Perfil
    </a>

    <?php if ($userNivel === 0 && ($ctx['id'] ?? null) === null): ?>
    <div class="nav-section">Super Admin</div>
    <a href="<?= BASE_PATH ?>/admin/clientes" class="nav-item <?= adminActive('/admin/clientes', $uri) ?>">
      <span class="nav-icon"><i class="ph ph-buildings"></i></span> Clientes
    </a>
    <?php endif; ?>
  </nav>

</aside>

<div class="main-wrap">
  <header class="topbar">
    <span class="topbar-title">
      Configurações
      <?php if (!empty($ctx['nome']) && $ctx['nome'] !== 'Sistema'): ?>
        <span style="color:var(--accent);font-weight:700"> — <?= htmlspecialchars($ctx['nome']) ?></span>
      <?php endif; ?>
    </span>
    <div style="display:flex;align-items:center;gap:16px">
      <a href="<?= BASE_PATH ?>/projetos" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:500;color:var(--accent);border:1px solid #bbf7d0;background:var(--accent-light);padding:5px 12px;border-radius:20px;transition:background .15s" onmouseover="this.style.background='#dcfce7'" onmouseout="this.style.background='var(--accent-light)'">
        <i class="ph ph-arrow-left" style="font-size:13px"></i> Voltar aos projetos
      </a>
      <div class="topbar-user">
        <div class="topbar-avatar" style="background:<?= $corAvatar ?>"><?= htmlspecialchars($iniciais) ?></div>
        <span><?= htmlspecialchars($userNome) ?></span>
      </div>
    </div>
  </header>

  <main class="main-content">
    <?= $content ?>
  </main>
</div>

</body>
</html>
