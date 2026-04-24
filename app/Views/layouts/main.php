<?php
$projetoDashboards = Auth::projetoDashboards();
$projetoNome       = Auth::projetoNome();
$userNome          = Auth::user()['nome']  ?? '';
$userEmail         = Auth::user()['email'] ?? '';
$userNivel         = Auth::nivel();
$lvls              = ['SuperAdmin','Administrador','Gestor','Analista','Visualizador'];
$userRole          = $lvls[$userNivel] ?? 'Usuário';

$partes    = array_filter(explode(' ', trim($userNome)));
$iniciais  = strtoupper(substr($partes[0] ?? 'U', 0, 1) . substr(end($partes) ?? '', 0, 1));
$paleta    = ['#16a34a','#2563eb','#9333ea','#ea580c','#0891b2','#db2777','#ca8a04'];
$corAvatar = $paleta[abs(crc32($userNome)) % count($paleta)];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $projetoNome ? htmlspecialchars($projetoNome) . ' — ' : '' ?><?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=DM+Sans:wght@400;500;700&family=Playfair+Display:wght@800&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
a{text-decoration:none;color:inherit}
button{font-family:inherit;cursor:pointer}

:root{
  --bg:#f6f6f6;
  --card:#fff;
  --accent:#16a34a;
  --accent-light:#f0fdf4;
  --accent-dark:#15803d;
  --text:#111827;
  --muted:#6b7280;
  --border:#e5e7eb;
  --sidebar-w:210px;
  --red:#dc2626;
  --red-light:#fef2f2;
  --gold:#C9A84C;
}

body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex}

/* ── SIDEBAR ── */
.sidebar{width:var(--sidebar-w);background:#fff;border-right:1px solid var(--border);display:flex;flex-direction:column;min-height:100vh;flex-shrink:0;position:fixed;top:0;left:0;bottom:0;z-index:100}
.sidebar-logo{display:flex;align-items:center;gap:10px;padding:18px 16px 16px;border-bottom:1px solid var(--border);flex-shrink:0}
.logo-icon{width:30px;height:30px;background:var(--accent);border-radius:7px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
.logo-text{font-size:15px;font-weight:700;color:var(--text);letter-spacing:-.3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sidebar-nav{flex:1;padding:10px 0;overflow-y:auto}
.nav-section{font-size:10px;font-weight:600;letter-spacing:.07em;color:#9ca3af;text-transform:uppercase;padding:14px 16px 5px}
.nav-item{display:flex;align-items:center;gap:9px;padding:8px 16px;font-size:13px;font-weight:500;color:#4b5563;border-left:3px solid transparent;transition:all .12s;cursor:pointer}
.nav-item:hover{color:var(--text);background:#f9fafb}
.nav-item.active{color:var(--accent);background:var(--accent-light);border-left-color:var(--accent);font-weight:600}
.nav-icon{font-size:18px;width:20px;text-align:center;flex-shrink:0;line-height:1;display:flex;align-items:center;justify-content:center}
.sidebar-footer{border-top:1px solid var(--border);padding:12px 14px;flex-shrink:0}
.sf-user{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.sf-avatar{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#fff;flex-shrink:0}
.sf-name{font-size:12px;font-weight:600;color:var(--text);line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sf-role{font-size:10px;color:var(--muted)}
.sf-link{font-size:11px;color:var(--muted);transition:color .12s;display:inline-flex;align-items:center;gap:4px}
.sf-link:hover{color:var(--accent)}

/* ── TOPBAR ── */
.main-wrap{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh}
.topbar{background:#fff;border-bottom:1px solid var(--border);height:52px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:10px}
.topbar-projeto{display:flex;align-items:center;gap:7px;font-size:14px;font-weight:600;color:var(--text)}
.topbar-projeto-icon{color:var(--accent);font-size:13px}

/* Avatar + Dropdown no topbar (igual ao MediaTracker) */
.topbar-user{position:relative;display:flex;align-items:center;gap:8px;cursor:pointer;padding:4px 8px;border-radius:8px;transition:background .15s;user-select:none}
.topbar-user:hover{background:#f3f4f6}
.topbar-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;flex-shrink:0}
.topbar-caret{color:#9ca3af;font-size:10px;transition:transform .2s}
.topbar-user.open .topbar-caret{transform:rotate(180deg)}

.tb-dropdown{position:absolute;top:calc(100% + 8px);right:0;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.12);min-width:220px;padding:6px;display:none;z-index:400}
.tb-dropdown.open{display:block}
.tb-dd-head{padding:14px 14px 10px;border-bottom:1px solid #f3f4f6;margin-bottom:4px;text-align:center}
.tb-dd-avatar{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#fff;margin:0 auto 8px}
.tb-dd-name{font-size:14px;font-weight:700;color:var(--text)}
.tb-dd-email{font-size:11px;color:var(--muted);margin-top:2px}
.tb-dd-switch{display:flex!important;justify-content:center;width:100%;margin-top:10px;padding:7px 12px;background:#f3f4f6;border:none;border-radius:7px;font-size:13px;font-weight:600;color:var(--text);text-align:center;transition:background .15s;cursor:pointer}
.tb-dd-switch:hover{background:#e5e7eb}
.tb-dropdown hr{border:none;border-top:1px solid #f3f4f6;margin:4px 0}
.tb-dropdown a{display:flex;align-items:center;gap:10px;padding:8px 12px;font-size:13px;font-weight:500;color:#374151;border-radius:8px;transition:background .12s}
.tb-dropdown a:hover{background:#f3f4f6}
.tb-dropdown a.danger{color:var(--red)}
.tb-dropdown a.danger:hover{background:var(--red-light)}
.tb-dropdown .dd-icon{width:16px;height:16px;flex-shrink:0;opacity:.6}

/* ── CONTEÚDO ── */
.main-content{flex:1;padding:24px 28px}

/* ── COMPONENTES COMPAT ── */
.page-header{margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.page-title{font-family:'Playfair Display',serif;font-size:24px;font-weight:800;line-height:1.1}
.btn-primary{padding:9px 18px;background:var(--accent);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;transition:background .15s}
.btn-primary:hover{background:var(--accent-dark)}
.btn-sm{padding:7px 13px;font-size:13px;font-weight:500;font-family:inherit;border-radius:8px;cursor:pointer;border:1.5px solid var(--border);background:var(--card);color:var(--text);transition:all .15s}
.btn-sm:hover{border-color:var(--accent);color:var(--accent)}
.btn-danger{padding:7px 13px;background:transparent;color:var(--red);border:1.5px solid #fecaca;border-radius:8px;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer}
.btn-danger:hover{background:var(--red-light)}
.card-plain{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:22px}
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

/* ── RESPONSIVE ── */
.mob-menu-btn{display:none;position:fixed;bottom:20px;right:20px;z-index:200;width:46px;height:46px;border-radius:50%;background:var(--accent);color:#fff;border:none;font-size:22px;box-shadow:0 4px 16px rgba(0,0,0,.2);align-items:center;justify-content:center;cursor:pointer}
@media(max-width:768px){
  .sidebar{transform:translateX(-100%);transition:transform .25s ease;z-index:200}
  .sidebar.open{transform:translateX(0)}
  .mob-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:190}
  .mob-overlay.open{display:block}
  .main-wrap{margin-left:0}
  .mob-menu-btn{display:flex}
  .main-content{padding:16px}
  .topbar{padding:0 14px}
  .cfg-grid{grid-template-columns:1fr!important}
  .mem-table th:nth-child(2),.mem-table td:nth-child(2){display:none}
}
</style>
</head>
<body>

<div class="mob-overlay" id="mobOverlay" onclick="closeSidebar()"></div>
<button class="mob-menu-btn" id="mobMenuBtn" onclick="toggleSidebar()">
  <i class="ph ph-list"></i>
</button>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="appSidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">K</div>
    <span class="logo-text"><?= APP_NAME ?></span>
  </div>

  <nav class="sidebar-nav">

    <?php if (!empty($projetoDashboards)): ?>
    <div class="nav-section">Painéis</div>
    <?php foreach ($projetoDashboards as $idx => $dash): ?>
    <?php
      if (empty($dash['nome'])) continue;
      $dashUrl = $dash['url'] ?? '';
      $isExternal = filter_var($dashUrl, FILTER_VALIDATE_URL) !== false;
      $href = $isExternal
        ? BASE_PATH . '/dashboard?idx=' . $idx
        : ($dashUrl ? BASE_PATH . $dashUrl : '#');
    ?>
    <a href="<?= htmlspecialchars($href) ?>" class="nav-item">
      <span class="nav-icon"><?= htmlspecialchars($dash['icone'] ?? '📊') ?></span>
      <?= htmlspecialchars($dash['nome']) ?>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="nav-section">Principal</div>
    <a href="<?= BASE_PATH ?>/parlamentares" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/parlamentares') ? 'active' : '' ?>">
      <span class="nav-icon"><i class="ph ph-buildings"></i></span> Parlamentares
    </a>
    <a href="<?= BASE_PATH ?>/sentinela" class="nav-item <?= str_contains($_SERVER['REQUEST_URI'], '/sentinela') ? 'active' : '' ?>">
      <span class="nav-icon"><i class="ph ph-eye"></i></span> Sentinela IA
    </a>

  </nav>

</aside>

<!-- ══ MAIN ══ -->
<div class="main-wrap">

  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-left">
      <?php if ($projetoNome): ?>
      <div class="topbar-projeto">
        <span style="display:inline-flex;align-items:center;gap:6px;background:var(--accent-light);border:1px solid #bbf7d0;border-radius:20px;padding:4px 12px 4px 8px;font-size:13px;font-weight:600;color:var(--accent-dark)">
          <span style="width:8px;height:8px;border-radius:50%;background:var(--accent);flex-shrink:0"></span>
          <?= htmlspecialchars($projetoNome) ?>
        </span>
      </div>
      <?php endif; ?>
    </div>

    <!-- Avatar + dropdown (igual MediaTracker) -->
    <div class="topbar-user" id="tbUserToggle">
      <div class="topbar-avatar" style="background:<?= $corAvatar ?>">
        <?= htmlspecialchars($iniciais) ?>
      </div>
      <svg class="topbar-caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>

      <div class="tb-dropdown" id="tbDropdown">
        <!-- Cabeçalho com avatar grande -->
        <div class="tb-dd-head">
          <div class="tb-dd-avatar" style="background:<?= $corAvatar ?>"><?= htmlspecialchars($iniciais) ?></div>
          <div class="tb-dd-name"><?= htmlspecialchars($userNome) ?></div>
          <div class="tb-dd-email"><?= htmlspecialchars($userEmail) ?></div>
          <a href="<?= BASE_PATH ?>/projetos" class="tb-dd-switch">Trocar de projeto</a>
        </div>

        <?php if ($userNivel <= 1): ?>
        <?php if ($userNivel === 1): ?>
        <a href="#" onclick="openCfgModal();return false;">
        <?php else: ?>
        <a href="<?= BASE_PATH ?>/admin">
        <?php endif; ?>
          <svg class="dd-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
          Configurações
        </a>
        <?php endif; ?>

        <a href="#">
          <svg class="dd-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
          Alterar Senha
        </a>
        <hr>
        <a href="<?= BASE_PATH ?>/logout" class="danger">
          <svg class="dd-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          Sair da conta
        </a>
      </div>
    </div>
  </header>

  <main class="main-content">
    <?= $content ?>
  </main>
</div>

<?php if ($userNivel === 1):
  $csrf = Auth::csrfToken();
  $paleta2 = ['#16a34a','#2563eb','#9333ea','#ea580c','#0891b2','#db2777','#ca8a04'];
  $partes2  = array_filter(explode(' ', trim($userNome)));
  $ini2     = strtoupper(substr($partes2[0] ?? 'U', 0, 1) . substr(end($partes2) ?? '', 0, 1));
  $cor2     = $paleta2[abs(crc32($userNome)) % count($paleta2)];
?>
<!-- Modal: Configurações -->
<div id="modalCfg" onclick="if(event.target===this)closeCfgModal()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px 28px 24px;width:100%;max-width:480px;margin:16px;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative">
    <button onclick="closeCfgModal()" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;line-height:1">&#x2715;</button>
    <h2 style="font-family:'Playfair Display',serif;font-size:20px;font-weight:800;margin-bottom:4px">Configurações</h2>
    <p style="font-size:13px;color:#6b7280;margin-bottom:20px">Gerencie sua equipe e perfil de acesso.</p>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <a href="<?= BASE_PATH ?>/admin/usuarios" style="display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 16px;text-decoration:none;color:#111827;transition:border-color .2s,box-shadow .2s" onmouseover="this.style.borderColor='#16a34a';this.style.boxShadow='0 6px 20px rgba(22,163,74,.1)'" onmouseout="this.style.borderColor='#e5e7eb';this.style.boxShadow='none'">
        <div style="width:42px;height:42px;background:#f0fdf4;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="ph ph-users" style="font-size:22px;color:#16a34a"></i></div>
        <div><div style="font-size:14px;font-weight:700">Membros da Equipe</div><div style="font-size:12px;color:#6b7280;margin-top:2px;line-height:1.4">Gerencie os usuários do projeto.</div></div>
      </a>
      <button onclick="openPerfilModal()" style="display:flex;align-items:center;gap:14px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 16px;cursor:pointer;text-align:left;transition:border-color .2s,box-shadow .2s;font-family:inherit" onmouseover="this.style.borderColor='#16a34a';this.style.boxShadow='0 6px 20px rgba(22,163,74,.1)'" onmouseout="this.style.borderColor='#e5e7eb';this.style.boxShadow='none'">
        <div style="width:42px;height:42px;background:#f0fdf4;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="ph ph-user-circle" style="font-size:22px;color:#16a34a"></i></div>
        <div><div style="font-size:14px;font-weight:700;color:#111827">Meu Perfil</div><div style="font-size:12px;color:#6b7280;margin-top:2px;line-height:1.4">Edite seu nome, e-mail e senha.</div></div>
      </button>
    </div>
  </div>
</div>

<!-- Modal: Meu Perfil -->
<div id="modalPerfil" onclick="if(event.target===this)closePerfilModal()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1001;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:460px;margin:16px;box-shadow:0 20px 60px rgba(0,0,0,.2);position:relative">
    <button onclick="closePerfilModal()" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;line-height:1">&#x2715;</button>

    <!-- Avatar -->
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:20px">
      <div id="pfAvatar" style="width:52px;height:52px;border-radius:50%;background:<?= $cor2 ?>;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;color:#fff;flex-shrink:0"><?= htmlspecialchars($ini2) ?></div>
      <div>
        <div id="pfNomeDisplay" style="font-size:16px;font-weight:700"><?= htmlspecialchars($userNome) ?></div>
        <div style="font-size:12px;color:#6b7280;margin-top:1px"><?= htmlspecialchars($userEmail) ?></div>
        <span style="display:inline-block;margin-top:5px;padding:2px 9px;border-radius:6px;font-size:11px;font-weight:700;background:#f0fdf4;color:#16a34a"><?= htmlspecialchars($userRole) ?></span>
      </div>
    </div>

    <div id="pfAlert" style="display:none;border-radius:8px;padding:9px 13px;font-size:13px;margin-bottom:14px"></div>

    <form id="pfForm">
      <input type="hidden" name="_token" value="<?= $csrf ?>">
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Nome completo</label>
        <input type="text" name="nome" id="pfNome" value="<?= htmlspecialchars($userNome) ?>" placeholder="Seu nome" style="width:100%;padding:9px 13px;border:1.5px solid #e5e7eb;border-radius:9px;font-size:14px;font-family:inherit;outline:none;background:#fafafa">
      </div>
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">E-mail</label>
        <input type="email" name="email" id="pfEmail" value="<?= htmlspecialchars($userEmail) ?>" placeholder="seu@email.com" style="width:100%;padding:9px 13px;border:1.5px solid #e5e7eb;border-radius:9px;font-size:14px;font-family:inherit;outline:none;background:#fafafa">
      </div>
      <hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0">
      <p style="font-size:12px;color:#6b7280;margin-bottom:12px">Alterar senha <span style="font-style:italic">(deixe em branco para manter a atual)</span></p>
      <div style="margin-bottom:14px">
        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Nova senha</label>
        <input type="password" name="senha" placeholder="Mínimo 6 caracteres" style="width:100%;padding:9px 13px;border:1.5px solid #e5e7eb;border-radius:9px;font-size:14px;font-family:inherit;outline:none;background:#fafafa">
      </div>
      <div style="margin-bottom:18px">
        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px">Confirmar nova senha</label>
        <input type="password" name="confirma" placeholder="Repita a nova senha" style="width:100%;padding:9px 13px;border:1.5px solid #e5e7eb;border-radius:9px;font-size:14px;font-family:inherit;outline:none;background:#fafafa">
      </div>
      <button type="submit" id="pfBtn" style="width:100%;padding:10px;background:#16a34a;color:#fff;border:none;border-radius:9px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer">Salvar alterações</button>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
(function(){
  const toggle = document.getElementById('tbUserToggle');
  const dd     = document.getElementById('tbDropdown');
  if (toggle && dd) {
    toggle.addEventListener('click', function(e){
      e.stopPropagation();
      const open = dd.classList.toggle('open');
      toggle.classList.toggle('open', open);
    });
    document.addEventListener('click', function(){
      dd.classList.remove('open');
      toggle.classList.remove('open');
    });
  }
})();

function toggleSidebar(){
  document.getElementById('appSidebar').classList.toggle('open');
  document.getElementById('mobOverlay').classList.toggle('open');
}
function closeSidebar(){
  document.getElementById('appSidebar').classList.remove('open');
  document.getElementById('mobOverlay').classList.remove('open');
}

<?php if ($userNivel === 1): ?>
function openCfgModal(){
  var m = document.getElementById('modalCfg');
  if(m){ m.style.display='flex'; document.body.style.overflow='hidden'; }
  document.getElementById('tbDropdown').classList.remove('open');
  document.getElementById('tbUserToggle').classList.remove('open');
}
function closeCfgModal(){
  var m = document.getElementById('modalCfg');
  if(m){ m.style.display='none'; document.body.style.overflow=''; }
}
function openPerfilModal(){
  closeCfgModal();
  var m = document.getElementById('modalPerfil');
  if(m){ m.style.display='flex'; document.body.style.overflow='hidden'; }
  var al = document.getElementById('pfAlert');
  if(al){ al.style.display='none'; al.textContent=''; }
}
function closePerfilModal(){
  var m = document.getElementById('modalPerfil');
  if(m){ m.style.display='none'; document.body.style.overflow=''; }
}
(function(){
  var form = document.getElementById('pfForm');
  if(!form) return;
  form.addEventListener('submit', async function(e){
    e.preventDefault();
    var btn = document.getElementById('pfBtn');
    btn.disabled = true;
    btn.textContent = 'Salvando...';
    var fd = new FormData(form);
    try {
      var res = await fetch('<?= BASE_PATH ?>/perfil/json', {method:'POST', body:fd});
      var json = await res.json();
      var al = document.getElementById('pfAlert');
      if(json.ok){
        al.style.display='block';
        al.style.background='#f0fdf4';
        al.style.color='#16a34a';
        al.style.border='1px solid #bbf7d0';
        al.textContent = json.msg;
        var nd = document.getElementById('pfNomeDisplay');
        if(nd && form.nome.value.trim()) nd.textContent = form.nome.value.trim();
      } else {
        al.style.display='block';
        al.style.background='#fef2f2';
        al.style.color='#dc2626';
        al.style.border='1px solid #fecaca';
        al.textContent = json.msg;
      }
    } catch(err) {
      console.error(err);
    }
    btn.disabled = false;
    btn.textContent = 'Salvar alterações';
  });
})();
<?php endif; ?>
</script>
</body>
</html>
