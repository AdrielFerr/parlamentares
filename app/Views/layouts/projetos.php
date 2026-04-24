<?php
/* Layout exclusivo da tela de seleção de projetos — sem sidebar */
$user    = Auth::user();
$lvls    = ['SuperAdmin','ClienteAdmin','Gestor','Analista','Visualizador'];
$nivel   = $lvls[Auth::nivel()] ?? 'Usuário';

/* Iniciais do avatar: até 2 letras */
$partes  = array_filter(explode(' ', trim($user['nome'] ?? 'U')));
$iniciais = strtoupper(substr($partes[0] ?? 'U', 0, 1) . substr(end($partes) ?? '', 0, 1));

/* Cor determinística baseada no nome */
$paleta  = ['#16a34a','#2563eb','#9333ea','#ea580c','#0891b2','#db2777','#ca8a04'];
$corIdx  = abs(crc32($user['nome'] ?? '')) % count($paleta);
$corAvatar = $paleta[$corIdx];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Meus Projetos — <?= APP_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;1,14..32,400&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ───────── Reset & Base ───────── */
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'Inter',sans-serif;background:#f0f2f5;color:#111827;min-height:100vh}
a{text-decoration:none;color:inherit}
button{font-family:inherit;cursor:pointer}
input,select,textarea{font-family:inherit}

/* ───────── Header fixo ───────── */
.hd{position:fixed;top:0;left:0;right:0;z-index:200;background:#fff;border-bottom:1px solid #e5e7eb;box-shadow:0 1px 4px rgba(0,0,0,.06);height:64px;display:flex;align-items:center;padding:0 32px}
.hd-brand{display:flex;align-items:center;gap:10px;flex:1}
.hd-brand-logo{width:34px;height:34px;background:#16a34a;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;letter-spacing:-.5px}
.hd-brand-name{font-weight:700;font-size:16px;color:#111827;letter-spacing:-.3px}
.hd-brand-sub{font-size:11px;color:#6b7280;font-weight:400}

/* ───────── Menu usuário ───────── */
.user-wrap{position:relative;display:flex;align-items:center;gap:10px;cursor:pointer;padding:6px 10px;border-radius:10px;transition:background .15s;user-select:none}
.user-wrap:hover{background:#f3f4f6}
.user-avatar{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#fff;flex-shrink:0}
.user-info{display:flex;flex-direction:column;line-height:1.3}
.user-name{font-weight:600;font-size:14px;color:#111827}
.user-email{font-size:11px;color:#6b7280}
.user-caret{color:#9ca3af;font-size:11px;margin-left:4px;transition:transform .2s}
.user-wrap.open .user-caret{transform:rotate(180deg)}

.dropdown{position:absolute;top:calc(100% + 8px);right:0;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.12);min-width:210px;padding:6px;display:none;z-index:300}
.dropdown.open{display:block}
.dropdown a{display:flex;align-items:center;gap:10px;padding:9px 12px;font-size:13px;font-weight:500;color:#374151;border-radius:8px;transition:background .12s}
.dropdown a:hover{background:#f3f4f6}
.dropdown a.danger{color:#dc2626}
.dropdown a.danger:hover{background:#fef2f2}
.dropdown hr{border:none;border-top:1px solid #f3f4f6;margin:4px 0}
.dropdown .dd-label{padding:8px 12px 4px;font-size:11px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em}

/* ───────── Corpo principal ───────── */
.pg-wrap{max-width:1240px;margin:0 auto;padding:88px 24px 48px}

/* ───────── Toast ───────── */
#toast{position:fixed;top:20px;right:20px;z-index:9999;transform:translateX(120%);transition:transform .35s cubic-bezier(.34,1.56,.64,1);pointer-events:none}
#toast.show{transform:translateX(0)}
.toast-inner{background:#fff;border:1px solid #e5e7eb;border-left:4px solid #16a34a;border-radius:10px;padding:14px 18px;display:flex;align-items:center;gap:10px;box-shadow:0 4px 20px rgba(0,0,0,.1);min-width:280px}
.toast-icon{width:22px;height:22px;background:#dcfce7;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#16a34a;font-size:12px;font-weight:700}
.toast-text{font-size:13px;font-weight:500;color:#111827}

/* ───────── Cabeçalho da página ───────── */
.pg-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:28px;flex-wrap:wrap}
.pg-title{font-size:26px;font-weight:700;color:#111827;letter-spacing:-.5px;margin-bottom:4px}
.pg-sub{font-size:14px;color:#6b7280;font-weight:400}

/* ───────── Linha de ações ───────── */
.actions-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.search-wrap{position:relative;flex:1;min-width:220px;max-width:380px}
.search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none}
.search-input{width:100%;padding:10px 12px 10px 38px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:14px;color:#111827;background:#fff;transition:border-color .2s,box-shadow .2s;outline:none}
.search-input:focus{border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.08)}
.search-input::placeholder{color:#9ca3af}
.btn-novo{display:flex;align-items:center;gap:8px;padding:10px 18px;background:#16a34a;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:600;transition:background .15s,transform .1s;white-space:nowrap}
.btn-novo:hover{background:#15803d}
.btn-novo:active{transform:scale(.98)}
.btn-novo svg{flex-shrink:0}

/* ───────── Grid de cards ───────── */
.cards-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px}
@media(max-width:768px){.cards-grid{grid-template-columns:1fr}}

/* ───────── Card ───────── */
.proj-card{background:#fff;border-radius:14px;border:1.5px solid #e5e7eb;box-shadow:0 1px 4px rgba(0,0,0,.05);padding:24px;transition:border-color .2s,box-shadow .2s,transform .15s;display:flex;flex-direction:column;gap:20px;position:relative;overflow:hidden}
.proj-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:transparent;border-radius:14px 0 0 14px;transition:background .2s}
.proj-card:hover{border-color:#bbf7d0;box-shadow:0 4px 20px rgba(22,163,74,.1);transform:translateY(-2px)}
.proj-card:hover::before{background:#16a34a}
.proj-card.card-active{border-color:#86efac;box-shadow:0 4px 20px rgba(22,163,74,.15)}
.proj-card.card-active::before{background:#16a34a}
.proj-card.hidden{display:none}

/* ───────── Topo do card ───────── */
.card-top{display:flex;align-items:flex-start;gap:12px}
.card-folder{width:44px;height:44px;background:#f0fdf4;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px;transition:background .2s}
.proj-card:hover .card-folder,.proj-card.card-active .card-folder{background:#dcfce7}
.card-title-wrap{flex:1;min-width:0}
.card-title-wrap h3{font-size:17px;font-weight:700;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px}
.card-fonte{font-size:12px;color:#6b7280;font-weight:400}
.cliente-tag{display:inline-block;padding:3px 9px;background:#eff6ff;color:#2563eb;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap;flex-shrink:0;align-self:flex-start}

/* ───────── Métricas ───────── */
.card-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:16px 0;border-top:1px solid #f3f4f6;border-bottom:1px solid #f3f4f6}
.metric-item{}
.metric-label{display:block;font-size:10px;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;margin-bottom:4px}
.metric-value{display:block;font-size:18px;font-weight:700;color:#111827;line-height:1.2}
.metric-value.small{font-size:13px}

/* ───────── Rodapé do card ───────── */
.card-footer{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:auto}
.btn-edit{padding:8px 16px;background:transparent;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-weight:500;color:#6b7280;transition:all .15s}
.btn-edit:hover{border-color:#9ca3af;color:#374151}
.btn-select{padding:9px 22px;background:#16a34a;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;transition:background .15s,transform .1s}
.btn-select:hover{background:#15803d}
.btn-select:active{transform:scale(.97)}
.btn-select.loading{opacity:.7;pointer-events:none}

/* ───────── Empty state ───────── */
.empty-state{grid-column:1/-1;text-align:center;padding:72px 24px;background:#fff;border-radius:14px;border:1.5px dashed #e5e7eb}
.empty-state .es-icon{font-size:48px;margin-bottom:16px;opacity:.5}
.empty-state h3{font-size:18px;font-weight:600;color:#374151;margin-bottom:8px}
.empty-state p{font-size:14px;color:#9ca3af;margin-bottom:20px}
.es-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#16a34a;color:#fff;border-radius:10px;font-size:14px;font-weight:600;transition:background .15s}
.es-btn:hover{background:#15803d}

/* ───────── Modal ───────── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:500;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;pointer-events:none;transition:opacity .2s}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal-box{background:#fff;border-radius:16px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);transform:translateY(16px) scale(.98);transition:transform .25s cubic-bezier(.34,1.2,.64,1)}
.modal-overlay.open .modal-box{transform:translateY(0) scale(1)}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:22px 24px 0}
.modal-header h2{font-size:18px;font-weight:700;color:#111827}
.modal-close{width:32px;height:32px;border:none;background:#f3f4f6;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#6b7280;font-size:18px;transition:background .15s}
.modal-close:hover{background:#e5e7eb}
.modal-body{padding:20px 24px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:540px){.form-row{grid-template-columns:1fr}}
.fg{margin-bottom:16px}
.fg label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px}
.fg input,.fg select,.fg textarea{width:100%;padding:9px 13px;border:1.5px solid #e5e7eb;border-radius:9px;font-size:14px;color:#111827;background:#fff;outline:none;transition:border-color .2s}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.07)}
.fg textarea{resize:vertical;min-height:90px}
.fg .hint{font-size:11px;color:#9ca3af;margin-top:4px}
.section-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f3f4f6}

/* Dashboards dinâmicos no modal */
.dash-item{display:flex;flex-direction:column;gap:8px;margin-bottom:10px;padding:12px;background:#f9fafb;border-radius:9px;border:1px solid #f3f4f6}
.dash-item input{padding:7px 10px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:13px;color:#111827;background:#fff;outline:none;min-width:0}
.dash-item input:focus{border-color:#16a34a}
.dash-item select{padding:7px 8px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:14px;background:#fff;outline:none;flex-shrink:0;cursor:pointer}
.dash-item select:focus{border-color:#16a34a}
.dash-rm{width:28px;height:28px;flex-shrink:0;background:transparent;border:1.5px solid #fca5a5;border-radius:7px;color:#dc2626;font-size:16px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;padding:0}
.dash-rm:hover{background:#fef2f2}
.dash-token-btn{flex-shrink:0;padding:6px 10px;border-radius:7px;border:1.5px dashed #d1d5db;background:#fff;font-size:13px;cursor:pointer;color:#6b7280;transition:all .15s;white-space:nowrap}
.dash-token-btn:hover{border-color:#16a34a;color:#16a34a;background:#f0fdf4}
.dash-token-row{display:flex;gap:8px;align-items:center;background:#fffbeb;border-radius:7px;padding:8px 10px;border:1.5px solid #fde68a}
.dash-token-clear{flex-shrink:0;width:24px;height:24px;border-radius:5px;border:none;background:transparent;color:#9ca3af;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0}
.dash-token-clear:hover{color:#dc2626}
.btn-add-dash{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:1.5px solid #16a34a;border-radius:8px;background:transparent;color:#16a34a;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;margin-top:6px}
.btn-add-dash:hover{background:#f0fdf4}
.modal-footer{display:flex;align-items:center;justify-content:flex-end;gap:10px;padding:16px 24px;border-top:1px solid #f3f4f6}
.btn-cancel{padding:9px 18px;background:#f3f4f6;border:none;border-radius:9px;font-size:14px;font-weight:500;color:#6b7280;cursor:pointer;transition:background .15s}
.btn-cancel:hover{background:#e5e7eb}
.btn-save{padding:9px 22px;background:#16a34a;border:none;border-radius:9px;font-size:14px;font-weight:600;color:#fff;cursor:pointer;transition:background .15s}
.btn-save:hover{background:#15803d}
.btn-save:disabled{opacity:.6;pointer-events:none}
.modal-error{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:14px;display:none}
.modal-error.show{display:block}
</style>
</head>
<body>

<!-- ════════ HEADER ════════ -->
<header class="hd">
  <div class="hd-brand">
    <div class="hd-brand-logo">K</div>
    <div>
      <div class="hd-brand-name"><?= APP_NAME ?></div>
      <div class="hd-brand-sub">Inteligência Legislativa</div>
    </div>
  </div>

  <div class="user-wrap" id="userToggle">
    <div class="user-avatar" style="background:<?= $corAvatar ?>">
      <?= htmlspecialchars($iniciais) ?>
    </div>
    <div class="user-info">
      <span class="user-name"><?= htmlspecialchars($user['nome'] ?? '') ?></span>
      <span class="user-email"><?= htmlspecialchars($user['email'] ?? '') ?></span>
    </div>
    <svg class="user-caret" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9l6 6 6-6"/></svg>

    <div class="dropdown" id="userDropdown">
      <div class="dd-label"><?= htmlspecialchars($nivel) ?></div>
      <a href="<?= BASE_PATH ?>/admin">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        Configurações
      </a>
      <a href="#">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
        Alterar Senha
      </a>
      <hr>
      <a href="<?= BASE_PATH ?>/logout" class="danger">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Sair da conta
      </a>
    </div>
  </div>
</header>

<!-- Toast de notificação -->
<div id="toast">
  <div class="toast-inner">
    <div class="toast-icon">✓</div>
    <span class="toast-text" id="toastText"></span>
  </div>
</div>

<!-- ════════ CONTEÚDO ════════ -->
<main>
  <?= $content ?>
</main>

<script>
/* Dropdown do usuário */
(function(){
  const toggle = document.getElementById('userToggle');
  const dd     = document.getElementById('userDropdown');
  if (!toggle || !dd) return;
  toggle.addEventListener('click', function(e){
    e.stopPropagation();
    const open = dd.classList.toggle('open');
    toggle.classList.toggle('open', open);
  });
  document.addEventListener('click', function(){
    dd.classList.remove('open');
    toggle.classList.remove('open');
  });
})();

/* Toast global */
function showToast(msg, duration) {
  duration = duration || 3000;
  const el   = document.getElementById('toast');
  const text = document.getElementById('toastText');
  text.textContent = msg;
  el.classList.add('show');
  setTimeout(function(){ el.classList.remove('show'); }, duration);
}
</script>
</body>
</html>
