<?php
/* ─────────────────────────────────────────────────────────────
 * View: Seleção de Projetos
 * Layout: projetos (sem sidebar)
 * ───────────────────────────────────────────────────────────── */

/* Projeto atualmente selecionado na sessão */
$projetoAtivo = Auth::projetoId();

/* Fontes indexadas por id para lookup rápido */
$fontesIdx = [];
foreach ($fontes as $f) {
    $fontesIdx[$f['id']] = $f;
}
?>

<div class="pg-wrap">

  <!-- ════ Cabeçalho + ações na mesma linha ════ -->
  <div class="pg-head">
    <div>
      <h1 class="pg-title">Meus Projetos</h1>
      <p class="pg-sub">Gerencie e monitore suas casas legislativas</p>
    </div>
    <div class="actions-row">
      <div class="search-wrap">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" id="searchInput" class="search-input" placeholder="Buscar projeto...">
      </div>
      <?php if (Auth::isSuperAdmin()): ?>
      <button class="btn-novo" onclick="abrirModalNovo()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <path d="M12 5v14M5 12h14"/>
        </svg>
        Novo Projeto
      </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ════ Grid de cards ════ -->
  <div class="cards-grid" id="cardsGrid">

    <?php if (empty($projetos)): ?>
    <div class="empty-state">
      <div class="es-icon">📁</div>
      <h3>Nenhum projeto ainda</h3>
      <p>Crie seu primeiro projeto para começar a monitorar sua casa legislativa.</p>
      <?php if (Auth::isSuperAdmin()): ?>
      <button class="es-btn" onclick="abrirModalNovo()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
        Criar primeiro projeto
      </button>
      <?php endif; ?>
    </div>

    <?php else: ?>
    <?php foreach ($projetos as $p): ?>
    <?php
      $ativo      = ((int)$p['id'] === (int)$projetoAtivo);
      $parlCount  = $p['parl_count'] ?? 0;
      $fonteLabel = $p['fonte_label'] ?? '—';
      $clienteNome= $p['cliente_nome'] ?? null;
    ?>
    <div class="proj-card <?= $ativo ? 'card-active' : '' ?>"
         id="card-<?= $p['id'] ?>"
         data-nome="<?= htmlspecialchars(strtolower($p['nome'])) ?>">

      <!-- ── Topo do card ── -->
      <div class="card-top">
        <div class="card-folder">📁</div>
        <div class="card-title-wrap">
          <h3><?= htmlspecialchars($p['nome']) ?></h3>
          <span class="card-fonte"><?= htmlspecialchars($fonteLabel) ?></span>
        </div>
        <?php if (Auth::isSuperAdmin() && $clienteNome): ?>
        <span class="cliente-tag"><?= htmlspecialchars($clienteNome) ?></span>
        <?php endif; ?>
      </div>

      <!-- ── Métricas ── -->
      <div class="card-metrics">
        <div class="metric-item">
          <span class="metric-label">Parlamentares</span>
          <span class="metric-value"><?= number_format($parlCount) ?></span>
        </div>
        <div class="metric-item">
          <span class="metric-label">Legislatura</span>
          <span class="metric-value small">Atual</span>
        </div>
        <div class="metric-item">
          <span class="metric-label">Fonte</span>
          <span class="metric-value small"><?= htmlspecialchars(mb_substr($fonteLabel, 0, 12)) ?></span>
        </div>
      </div>

      <!-- ── Rodapé ── -->
      <div class="card-footer">
        <?php if (Auth::isSuperAdmin()): ?>
        <button class="btn-edit" onclick="abrirModalEditar(<?= $p['id'] ?>)">Editar</button>
        <?php endif; ?>
        <button class="btn-select"
                onclick="selecionarProjeto(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['nome'])) ?>)"
                id="btn-select-<?= $p['id'] ?>">
          Selecionar
        </button>
      </div>

    </div>
    <?php endforeach; ?>
    <?php endif; ?>

  </div><!-- /cards-grid -->
</div><!-- /pg-wrap -->


<!-- ════════════════════════════════════════════════════════
     MODAL NOVO / EDITAR PROJETO
  ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalOverlay" onclick="fecharModal(event)">
  <div class="modal-box" onclick="event.stopPropagation()">

    <div class="modal-header">
      <h2 id="modalTitulo">Novo Projeto</h2>
      <button class="modal-close" onclick="fecharModal()">&times;</button>
    </div>

    <div class="modal-body">
      <div class="modal-error" id="modalErro"></div>

      <input type="hidden" id="modalProjetoId" value="">

      <!-- Cliente (somente Super Admin) -->
      <?php if (Auth::isSuperAdmin()): ?>
      <div class="fg" id="fgCliente">
        <label>Cliente vinculado <span style="color:#dc2626">*</span></label>
        <div style="display:flex;border:1.5px solid #e5e7eb;border-radius:9px;overflow:hidden;margin-bottom:8px">
          <button type="button" id="btnModoExistente" onclick="setModoCliente('existente')"
            style="flex:1;padding:7px 10px;font-size:12px;font-weight:600;font-family:inherit;border:none;cursor:pointer;background:#16a34a;color:#fff;transition:all .15s">
            Selecionar existente
          </button>
          <button type="button" id="btnModoNovo" onclick="setModoCliente('novo')"
            style="flex:1;padding:7px 10px;font-size:12px;font-weight:600;font-family:inherit;border:none;cursor:pointer;background:transparent;color:#6b7280;transition:all .15s">
            + Criar novo
          </button>
        </div>
        <div id="blocoClienteExistente">
          <select id="fCliente">
            <option value="">Selecione o cliente…</option>
            <?php foreach ($clientes as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div id="blocoClienteNovo" style="display:none">
          <input type="text" id="fNovoClienteNome" placeholder="Nome do novo cliente">
        </div>
      </div>
      <?php endif; ?>

      <!-- Nome -->
      <div class="fg">
        <label>Nome do Projeto <span style="color:#dc2626">*</span></label>
        <input type="text" id="fNome" placeholder="Ex: Monitoramento CMJP 2025">
      </div>

      <!-- Fonte + URL -->
      <div class="form-row">
        <div class="fg">
          <label>Fonte Legislativa <span style="color:#dc2626">*</span></label>
          <select id="fFonte" onchange="onFonteChange()">
            <option value="">Selecione…</option>
            <?php foreach ($fontes as $f): ?>
            <option value="<?= $f['id'] ?>"
                    data-url="<?= htmlspecialchars($f['url']) ?>">
              <?= htmlspecialchars($f['label']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label>URL da Fonte</label>
          <input type="text" id="fUrl" placeholder="Preenchida automaticamente" readonly
                 style="background:#f9fafb;color:#6b7280">
        </div>
      </div>

      <!-- API OpenAI -->
      <div class="form-row">
        <div class="fg">
          <label>Chave API OpenAI</label>
          <input type="password" id="fApiKey" placeholder="sk-proj-…">
          <span class="hint">Criptografada. Deixe em branco para manter.</span>
        </div>
        <div class="fg">
          <label>Modelo OpenAI</label>
          <select id="fModelo">
            <option value="gpt-4o">gpt-4o</option>
            <option value="gpt-4o-mini">gpt-4o-mini</option>
            <option value="gpt-3.5-turbo">gpt-3.5-turbo</option>
          </select>
        </div>
      </div>

      <!-- Dashboards -->
      <div style="margin-top:20px">
        <div class="section-label">Dashboards do Menu</div>
        <div id="dashList"></div>
        <button class="btn-add-dash" type="button" onclick="adicionarDashboard()">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
          Adicionar Dashboard
        </button>
      </div>

      <!-- Administradores com acesso (somente SuperAdmin, opcional) -->
      <?php if (!empty($adminUsers)): ?>
      <div style="margin-top:20px">
        <div class="section-label">Administradores com acesso</div>
        <p style="font-size:12px;color:#6b7280;margin-bottom:10px">Opcional — define quais administradores do sistema podem visualizar este projeto.</p>
        <div id="adminCheckList" style="display:flex;flex-direction:column;gap:8px">
          <?php foreach ($adminUsers as $au): ?>
          <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;border:1.5px solid #e5e7eb;border-radius:9px;cursor:pointer;transition:border-color .15s;font-size:13px"
                 onmouseover="this.style.borderColor='#16a34a'" onmouseout="if(!this.querySelector('input').checked)this.style.borderColor='#e5e7eb'">
            <input type="checkbox" class="admin-check" value="<?= $au['id'] ?>"
                   style="width:16px;height:16px;accent-color:#16a34a;cursor:pointer;flex-shrink:0"
                   onchange="this.closest('label').style.borderColor=this.checked?'#16a34a':'#e5e7eb'">
            <div>
              <div style="font-weight:600;color:#111827"><?= htmlspecialchars($au['nome']) ?></div>
              <div style="font-size:11px;color:#9ca3af"><?= htmlspecialchars($au['email']) ?></div>
            </div>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>


    </div><!-- /modal-body -->

    <div class="modal-footer">
      <button class="btn-cancel" onclick="fecharModal()">Cancelar</button>
      <button class="btn-save" id="btnSalvar" onclick="salvarProjeto()">Salvar Projeto</button>
    </div>

  </div><!-- /modal-box -->
</div><!-- /modal-overlay -->


<!-- ════════════════════════════════════════════════════════
     SCRIPTS
  ══════════════════════════════════════════════════════════ -->
<script>
/* Constantes PHP → JS */
const CSRF      = <?= json_encode(Auth::csrfToken()) ?>;
const BASE_PATH = <?= json_encode(BASE_PATH) ?>;

document.addEventListener('DOMContentLoaded', function() {
  renderDashboards();
});

/* ─── Busca (filtro client-side) ─── */
document.getElementById('searchInput').addEventListener('input', function() {
  const q = this.value.toLowerCase().trim();
  document.querySelectorAll('.proj-card').forEach(function(card) {
    const nome = card.dataset.nome || '';
    card.classList.toggle('hidden', q !== '' && !nome.includes(q));
  });
});

/* ─── Selecionar projeto ─── */
function selecionarProjeto(id, nome) {
  const btn = document.getElementById('btn-select-' + id);
  if (btn) { btn.classList.add('loading'); btn.textContent = '…'; }

  fetch(BASE_PATH + '/projetos/selecionar', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: '_token=' + encodeURIComponent(CSRF) + '&projeto_id=' + encodeURIComponent(id)
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.ok) {
      showToast('Projeto "' + nome + '" selecionado com sucesso!');
      /* Marca o card ativo visualmente */
      document.querySelectorAll('.proj-card').forEach(function(c){ c.classList.remove('card-active'); });
      const card = document.getElementById('card-' + id);
      if (card) card.classList.add('card-active');
      /* Redireciona após 1.8s para o sistema principal */
      setTimeout(function(){ window.location.href = data.redirect; }, 1800);
    } else {
      if (btn) { btn.classList.remove('loading'); btn.textContent = 'Selecionar'; }
      alert(data.error || 'Erro ao selecionar projeto.');
    }
  })
  .catch(function() {
    if (btn) { btn.classList.remove('loading'); btn.textContent = 'Selecionar'; }
    alert('Erro de comunicação. Tente novamente.');
  });
}

/* ─── Modal: abrir (novo projeto) ─── */
function abrirModalNovo() {
  document.getElementById('modalTitulo').textContent = 'Novo Projeto';
  document.getElementById('modalProjetoId').value = '';
  limparFormModal();
  renderDashboards();
  document.getElementById('modalOverlay').classList.add('open');
  setTimeout(function(){ document.getElementById('fNome').focus(); }, 200);
}

/* ─── Modal: abrir (editar projeto) ─── */
function abrirModalEditar(id) {
  document.getElementById('modalTitulo').textContent = 'Editar Projeto';
  document.getElementById('modalProjetoId').value = id;
  document.getElementById('modalErro').classList.remove('show');

  /* Busca dados do projeto via AJAX */
  fetch(BASE_PATH + '/projetos/dados?id=' + id)
    .then(function(r){ return r.json(); })
    .then(function(p) {
      if (!p) return;
      document.getElementById('fNome').value    = p.nome || '';
      document.getElementById('fUrl').value     = p.fonte_url || '';
      document.getElementById('fModelo').value  = p.openai_model || 'gpt-4o';
      /* Cliente (super admin) */
      const sel = document.getElementById('fCliente');
      if (sel) sel.value = p.cliente_id || '';
      /* Fonte */
      const fsel = document.getElementById('fFonte');
      if (fsel) fsel.value = p.fonte_id || '';
      /* Dashboards */
      dashboards = JSON.parse(p.dashboards_json || '[]');
      dashboards = dashboards.map(function(d){ return Object.assign({token:''}, d); });
      if (!dashboards.length) dashboards = [{ nome: 'Dashboard', url: '', icone: '📊', token: '' }];
      renderDashboards();
      /* Administradores */
      var adminIds = p.admin_ids || [];
      document.querySelectorAll('.admin-check').forEach(function(cb){
        var checked = adminIds.indexOf(parseInt(cb.value)) !== -1;
        cb.checked = checked;
        cb.closest('label').style.borderColor = checked ? '#16a34a' : '#e5e7eb';
      });
    });

  document.getElementById('modalOverlay').classList.add('open');
}

/* ─── Modal: fechar ─── */
function fecharModal(evt) {
  if (evt && evt.target !== document.getElementById('modalOverlay')) return;
  document.getElementById('modalOverlay').classList.remove('open');
}
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') fecharModal(); });

var modoCliente = 'existente';

function setModoCliente(modo) {
  modoCliente = modo;
  var isExistente = modo === 'existente';
  document.getElementById('blocoClienteExistente').style.display = isExistente ? '' : 'none';
  document.getElementById('blocoClienteNovo').style.display      = isExistente ? 'none' : '';
  document.getElementById('btnModoExistente').style.background   = isExistente ? '#16a34a' : 'transparent';
  document.getElementById('btnModoExistente').style.color        = isExistente ? '#fff' : '#6b7280';
  document.getElementById('btnModoNovo').style.background        = isExistente ? 'transparent' : '#16a34a';
  document.getElementById('btnModoNovo').style.color             = isExistente ? '#6b7280' : '#fff';
  if (!isExistente) setTimeout(function(){ var el = document.getElementById('fNovoClienteNome'); if (el) el.focus(); }, 50);
}

function limparFormModal() {
  ['fNome','fUrl','fApiKey','fNovoClienteNome'].forEach(function(id){
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  const fc = document.getElementById('fCliente'); if (fc) fc.value = '';
  const ff = document.getElementById('fFonte');   if (ff) ff.value = '';
  const fm = document.getElementById('fModelo');  if (fm) fm.value = 'gpt-4o';
  document.getElementById('modalErro').classList.remove('show');
  dashboards = [{ nome: 'Dashboard', url: '', icone: '📊' }];
  setModoCliente('existente');
  document.querySelectorAll('.admin-check').forEach(function(cb){
    cb.checked = false;
    cb.closest('label').style.borderColor = '#e5e7eb';
  });
}

/* ─── Auto-preenche URL ao selecionar fonte ─── */
function onFonteChange() {
  const sel = document.getElementById('fFonte');
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('fUrl').value = opt ? (opt.dataset.url || '') : '';
}

/* ─── Dashboards dinâmicos ─── */
var dashboards = [{ nome: 'Dashboard', url: '', icone: '📊', token: '' }];
var iconeOpcoes = ['📊','📈','🗺️','🏛️','📋','🔍','⚡','🏅'];

function toggleDashToken(i) {
  dashboards[i]._showToken = !dashboards[i]._showToken;
  if (!dashboards[i]._showToken) dashboards[i].token = '';
  renderDashboards();
  /* Foca no campo de token se acabou de abrir */
  if (dashboards[i]._showToken) {
    var inputs = document.querySelectorAll('#dashList .dash-item');
    var pw = inputs[i] && inputs[i].querySelector('input[type=password]');
    if (pw) pw.focus();
  }
}

function renderDashboards() {
  var html = dashboards.map(function(d, i) {
    var opcoesHTML = iconeOpcoes.map(function(ic) {
      return '<option value="' + ic + '"' + (d.icone === ic ? ' selected' : '') + '>' + ic + '</option>';
    }).join('');
    var hasToken = !!(d.token);
    var showToken = d._showToken || hasToken;
    var tokenRow = showToken
      ? '<div class="dash-token-row">' +
          '<span style="font-size:11px;color:#6b7280;white-space:nowrap">Token:</span>' +
          '<input type="password" placeholder="Cole o token de autenticação" value="' + escHtml(d.token || '') + '" ' +
            'style="font-family:monospace;flex:1" oninput="dashboards[' + i + '].token = this.value">' +
          '<button type="button" class="dash-token-clear" onclick="toggleDashToken(' + i + ')" title="Remover token">✕</button>' +
        '</div>'
      : '';
    return '<div class="dash-item">' +
      '<div style="display:flex;gap:8px;align-items:center">' +
        '<input type="text" placeholder="Nome (ex: Painel Eleitoral)" value="' + escHtml(d.nome) + '" style="flex:1" ' +
          'oninput="dashboards[' + i + '].nome = this.value">' +
        '<select onchange="dashboards[' + i + '].icone = this.value" style="width:52px">' + opcoesHTML + '</select>' +
        '<button class="dash-rm" type="button" onclick="removerDashboard(' + i + ')" title="Remover">&times;</button>' +
      '</div>' +
      '<div style="display:flex;gap:8px;align-items:center">' +
        '<input type="text" placeholder="URL do embed (ex: https://builder.keekconecta.com.br/embed/...)" value="' + escHtml(d.url) + '" style="flex:1" ' +
          'oninput="dashboards[' + i + '].url = this.value">' +
        (!showToken
          ? '<button type="button" class="dash-token-btn" onclick="toggleDashToken(' + i + ')" title="Adicionar token de autenticação">🔐</button>'
          : '') +
      '</div>' +
      tokenRow +
    '</div>';
  }).join('');
  document.getElementById('dashList').innerHTML = html;
}

function adicionarDashboard() {
  dashboards.push({ nome: '', url: '', icone: '📊', token: '' });
  renderDashboards();
  /* Foca no último input nome */
  var itens = document.querySelectorAll('#dashList .dash-item input[type=text]');
  if (itens.length) itens[itens.length - 2].focus();
}

function removerDashboard(i) {
  if (dashboards.length <= 1) { dashboards[0] = { nome: '', url: '', icone: '📊', token: '' }; renderDashboards(); return; }
  dashboards.splice(i, 1);
  renderDashboards();
}

function escHtml(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

/* ─── Salvar projeto (AJAX) ─── */
function salvarProjeto() {
  const erroEl  = document.getElementById('modalErro');
  const btnSave = document.getElementById('btnSalvar');
  erroEl.classList.remove('show');

  const id      = document.getElementById('modalProjetoId').value;
  const nome    = document.getElementById('fNome').value.trim();
  const fonteId = document.getElementById('fFonte').value;
  const apiKey  = document.getElementById('fApiKey').value.trim();
  const modelo  = document.getElementById('fModelo').value;

  if (!nome) {
    erroEl.textContent = 'O nome do projeto é obrigatório.';
    erroEl.classList.add('show');
    document.getElementById('fNome').focus();
    return;
  }
  if (!fonteId) {
    erroEl.textContent = 'Selecione a fonte legislativa.';
    erroEl.classList.add('show');
    document.getElementById('fFonte').focus();
    return;
  }

  btnSave.disabled = true;
  btnSave.textContent = 'Salvando…';

  /* Se for novo cliente, cria primeiro; depois cria o projeto */
  var clientePromise;
  var selCli = document.getElementById('fCliente');

  if (modoCliente === 'novo') {
    var novoNome = (document.getElementById('fNovoClienteNome') || {}).value;
    novoNome = novoNome ? novoNome.trim() : '';
    if (!novoNome) {
      erroEl.textContent = 'Informe o nome do novo cliente.';
      erroEl.classList.add('show');
      btnSave.disabled = false;
      btnSave.textContent = 'Salvar Projeto';
      document.getElementById('fNovoClienteNome').focus();
      return;
    }
    clientePromise = fetch(BASE_PATH + '/admin/clientes/ajax', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: '_token=' + encodeURIComponent(CSRF) + '&nome=' + encodeURIComponent(novoNome)
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
      if (!data.ok) throw new Error(data.error || 'Erro ao criar cliente.');
      /* Adiciona o novo cliente ao select para próximas vezes */
      if (selCli) {
        var opt = document.createElement('option');
        opt.value = data.id; opt.textContent = data.nome;
        selCli.appendChild(opt);
      }
      return data.id;
    });
  } else {
    var cliId = selCli ? selCli.value : '';
    clientePromise = Promise.resolve(cliId);
  }

  clientePromise.then(function(cliId) {
    var adminIds = [];
    document.querySelectorAll('.admin-check:checked').forEach(function(cb){ adminIds.push(parseInt(cb.value)); });

    const body = new URLSearchParams({
      _token:          CSRF,
      nome:            nome,
      fonte_id:        fonteId,
      openai_key:      apiKey,
      openai_model:    modelo,
      dashboards_json: JSON.stringify(dashboards),
      cliente_id:      cliId || '',
      admin_ids:       JSON.stringify(adminIds)
    });
    if (id) body.append('id', id);

    return fetch(id ? BASE_PATH + '/projetos/ajax/editar' : BASE_PATH + '/projetos/ajax/criar', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function(r){ return r.json(); });
  })
  .then(function(data) {
    btnSave.disabled = false;
    btnSave.textContent = 'Salvar Projeto';
    if (data.ok) {
      fecharModal();
      showToast(id ? 'Projeto atualizado!' : 'Projeto criado com sucesso!');
      setTimeout(function(){ window.location.reload(); }, 1500);
    } else {
      erroEl.textContent = data.error || 'Erro ao salvar. Tente novamente.';
      erroEl.classList.add('show');
    }
  })
  .catch(function(err) {
    btnSave.disabled = false;
    btnSave.textContent = 'Salvar Projeto';
    erroEl.textContent = err.message || 'Erro de comunicação. Tente novamente.';
    erroEl.classList.add('show');
  });
}
</script>
