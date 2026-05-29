<?php
$projetoAtivo = Auth::projetoId();

$regiaoMeta = [
    'N'  => ['label' => 'Norte',        'cor' => '#0891b2', 'bg' => '#ecfeff'],
    'NE' => ['label' => 'Nordeste',     'cor' => '#ea580c', 'bg' => '#fff7ed'],
    'CO' => ['label' => 'Centro-Oeste', 'cor' => '#7c3aed', 'bg' => '#f5f3ff'],
    'SE' => ['label' => 'Sudeste',      'cor' => '#2563eb', 'bg' => '#eff6ff'],
    'S'  => ['label' => 'Sul',          'cor' => '#16a34a', 'bg' => '#f0fdf4'],
];
$reg = $regiaoMeta[$estado['regiao']] ?? $regiaoMeta['SE'];
$ufLower = strtolower($uf);
?>
<style>
.ep-wrap{max-width:960px;margin:0 auto;padding:88px 24px 48px}

/* ── Cabeçalho ── */
.ep-header{display:flex;align-items:center;gap:16px;margin-bottom:28px;flex-wrap:wrap}
.ep-back{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:#fff;border:1.5px solid #e5e7eb;border-radius:9px;font-size:13px;font-weight:600;color:#374151;cursor:pointer;text-decoration:none;transition:all .15s}
.ep-back:hover{border-color:#9ca3af;color:#111827}
.ep-flag-wrap{width:72px;height:48px;border-radius:10px;overflow:hidden;border:1.5px solid #e8e8e8;flex-shrink:0;background:#f0f0f0;position:relative}
.ep-flag{width:100%;height:100%;object-fit:cover;display:block}
.ep-flag-wrap.flag-fallback{display:flex;align-items:center;justify-content:center;background:var(--accent,#16a34a)}
.ep-flag-wrap.flag-fallback .ep-flag{display:none}
.ep-flag-fallback-text{font-size:16px;font-weight:800;color:#fff;letter-spacing:.3px}
.ep-info{flex:1}
.ep-title{font-size:22px;font-weight:800;color:#111827;margin-bottom:4px}
.ep-sub{font-size:13px;color:#6b7280;display:flex;align-items:center;gap:10px}
.ep-reg-badge{font-size:11px;font-weight:600;padding:2px 9px;border-radius:20px;display:inline-block}
.ep-count-badge{font-size:12px;font-weight:700;background:#f3f4f6;color:#6b7280;padding:3px 10px;border-radius:20px}

/* ── Lista de projetos ── */
.ep-list{display:flex;flex-direction:column;gap:10px}
.ep-proj-card{background:#fff;border:1.5px solid #e5e7eb;border-radius:14px;padding:16px 20px;display:flex;align-items:center;gap:16px;transition:box-shadow .15s}
.ep-proj-card:hover{box-shadow:0 4px 18px rgba(0,0,0,.08)}
.ep-proj-card.ativo{border-color:#bbf7d0;background:#f0fdf4}
.ep-proj-info{flex:1;min-width:0}
.ep-proj-nome{font-size:15px;font-weight:700;color:#111827;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px}
.ep-proj-meta{font-size:12px;color:#9ca3af}
.ep-proj-actions{display:flex;gap:7px;flex-shrink:0;align-items:center}

/* ── Botões ── */
.btn-sel{padding:7px 16px;background:#16a34a;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s;white-space:nowrap}
.btn-sel:hover{background:#15803d}
.btn-sel.loading{opacity:.6;pointer-events:none}
.btn-sel-ativo{background:#dcfce7;color:#15803d;border:1.5px solid #bbf7d0}
.btn-sel-ativo:hover{background:#bbf7d0}
.btn-ed{padding:7px 12px;background:transparent;border:1.5px solid #e5e7eb;border-radius:8px;font-size:13px;font-weight:500;color:#6b7280;cursor:pointer;transition:all .15s}
.btn-ed:hover{border-color:#9ca3af;color:#374151}
.btn-del{padding:7px 10px;background:transparent;border:1.5px solid #fecaca;border-radius:8px;color:#dc2626;cursor:pointer;transition:all .15s;display:flex;align-items:center}
.btn-del:hover{background:#fef2f2}

/* ── Vazio ── */
.ep-empty{text-align:center;padding:60px 20px;color:#9ca3af}
.ep-empty-icon{font-size:40px;margin-bottom:12px;opacity:.4}
.ep-empty-text{font-size:15px;font-weight:600;color:#6b7280;margin-bottom:6px}
.ep-empty-sub{font-size:13px;color:#9ca3af}
</style>

<div class="ep-wrap">

  <div class="ep-header">
    <a href="<?= BASE_PATH ?>/projetos" class="ep-back">
      <i class="ph ph-arrow-left"></i> Voltar
    </a>

    <div class="ep-flag-wrap" id="epFlagWrap" style="background:<?= $reg['cor'] ?>">
      <img class="ep-flag" id="epFlagImg"
           src="<?= BASE_PATH ?>/public/assets/bandeiras/<?= $ufLower ?>.png"
           alt="<?= htmlspecialchars($uf) ?>"
           onerror="this.style.display='none';document.getElementById('epFlagWrap').classList.add('flag-fallback');document.getElementById('epFlagFallback').style.display=''">
      <span id="epFlagFallback" class="ep-flag-fallback-text" style="display:none"><?= htmlspecialchars($uf) ?></span>
    </div>

    <div class="ep-info">
      <div class="ep-title"><?= htmlspecialchars($estado['nome']) ?></div>
      <div class="ep-sub">
        <span class="ep-reg-badge" style="background:<?= $reg['bg'] ?>;color:<?= $reg['cor'] ?>"><?= $reg['label'] ?></span>
        <span class="ep-count-badge"><?= count($projetos) ?> projeto<?= count($projetos) !== 1 ? 's' : '' ?></span>
      </div>
    </div>

    <button class="btn-novo" onclick="abrirModalNovo('<?= $uf ?>')">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
      Novo Projeto
    </button>
  </div>

  <?php if (empty($projetos)): ?>
  <div class="ep-empty">
    <div class="ep-empty-icon"><i class="ph ph-folder-open"></i></div>
    <div class="ep-empty-text">Nenhum projeto neste estado</div>
    <div class="ep-empty-sub">Clique em "Novo Projeto" para criar o primeiro.</div>
  </div>

  <?php else: ?>
  <div class="ep-list">
    <?php foreach ($projetos as $p):
      $ativo      = ((int)$p['id'] === (int)$projetoAtivo);
      $parlCount  = $p['parl_count'] ?? 0;
      $clienteNome = $p['cliente_nome'] ?? null;
    ?>
    <div class="ep-proj-card <?= $ativo ? 'ativo' : '' ?>" id="ep-card-<?= $p['id'] ?>">
      <div class="ep-proj-info">
        <span class="ep-proj-nome"><?= htmlspecialchars($p['nome']) ?></span>
        <span class="ep-proj-meta">
          <?= number_format($parlCount) ?> parlamentares
          <?= $clienteNome ? ' · ' . htmlspecialchars($clienteNome) : '' ?>
          <?= !empty($p['fonte_label']) ? ' · ' . htmlspecialchars($p['fonte_label']) : '' ?>
        </span>
      </div>
      <div class="ep-proj-actions">
        <button class="btn-del" onclick="confirmarExcluir(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['nome'])) ?>)" title="Excluir">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
        </button>
        <button class="btn-ed" onclick="abrirModalEditar(<?= $p['id'] ?>)">Editar</button>
        <button class="btn-sel <?= $ativo ? 'btn-sel-ativo' : '' ?>"
                id="btn-select-<?= $p['id'] ?>"
                onclick="selecionarProjeto(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['nome'])) ?>)">
          <?= $ativo ? 'Ativo' : 'Selecionar' ?>
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>


<!-- ════════════════════════════════════════════════════════
     MODAL CONFIRMAR EXCLUSÃO
  ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalExcluirOverlay" onclick="fecharModalExcluir(event)">
  <div class="modal-box modal-box-sm" onclick="event.stopPropagation()">
    <div class="modal-header">
      <h2 style="color:#dc2626;display:flex;align-items:center;gap:10px">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
        Excluir Projeto
      </h2>
      <button class="modal-close" onclick="fecharModalExcluir()">&times;</button>
    </div>
    <div class="modal-body">
      <p style="font-size:14px;color:#374151;margin-bottom:8px">Tem certeza que deseja excluir o projeto:</p>
      <p id="excluirNomeProjeto" style="font-size:15px;font-weight:700;color:#111827;background:#f9fafb;border:1.5px solid #e5e7eb;border-radius:8px;padding:10px 14px;margin-bottom:12px"></p>
      <p style="font-size:13px;color:#6b7280">Esta ação não pode ser desfeita.</p>
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="fecharModalExcluir()">Cancelar</button>
      <button class="btn-danger-confirm" id="btnConfirmarExcluir" onclick="executarExcluir()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
        Excluir
      </button>
    </div>
  </div>
</div>


<!-- ════════════════════════════════════════════════════════
     MODAL NOVO / EDITAR PROJETO
  ══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalOverlay" onclick="fecharModal(event)">
  <div class="modal-box" onclick="event.stopPropagation()">
    <div class="modal-header">
      <h2 id="modalTitulo">Editar Projeto</h2>
      <button class="modal-close" onclick="fecharModal()">&times;</button>
    </div>
    <div class="modal-body">
      <div class="modal-error" id="modalErro"></div>
      <input type="hidden" id="modalProjetoId" value="">

      <!-- Cliente -->
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

      <!-- Nome -->
      <div class="fg">
        <label>Nome do Projeto <span style="color:#dc2626">*</span></label>
        <input type="text" id="fNome" placeholder="Ex: Monitoramento CMJP 2025">
      </div>

      <!-- Estado (UF) -->
      <div class="form-row" id="blocoUf">
        <div class="fg">
          <label>Estado <span style="color:#dc2626">*</span></label>
          <select id="fUf">
            <option value="">Selecione…</option>
            <?php
            $ufsOpcoes = ['AC'=>'Acre','AL'=>'Alagoas','AM'=>'Amazonas','AP'=>'Amapá','BA'=>'Bahia',
                'CE'=>'Ceará','DF'=>'Distrito Federal','ES'=>'Espírito Santo','GO'=>'Goiás',
                'MA'=>'Maranhão','MG'=>'Minas Gerais','MS'=>'Mato Grosso do Sul','MT'=>'Mato Grosso',
                'PA'=>'Pará','PB'=>'Paraíba','PE'=>'Pernambuco','PI'=>'Piauí','PR'=>'Paraná',
                'RJ'=>'Rio de Janeiro','RN'=>'Rio Grande do Norte','RO'=>'Rondônia','RR'=>'Roraima',
                'RS'=>'Rio Grande do Sul','SC'=>'Santa Catarina','SE'=>'Sergipe','SP'=>'São Paulo','TO'=>'Tocantins'];
            foreach ($ufsOpcoes as $sigla => $nome):
            ?>
            <option value="<?= $sigla ?>"><?= $sigla ?> — <?= htmlspecialchars($nome) ?></option>
            <?php endforeach; ?>
          </select>
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

      <!-- Usuários com acesso -->
      <?php if (!empty($todoUsuarios)): ?>
      <div style="margin-top:20px">
        <div class="section-label">Usuários com acesso</div>
        <p style="font-size:12px;color:#6b7280;margin-bottom:10px">Selecione quais usuários podem acessar este projeto. Um usuário pode estar em múltiplos projetos.</p>
        <input type="text" id="usuarioSearch" placeholder="Filtrar usuários…"
               style="width:100%;margin-bottom:10px;padding:7px 11px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:12px;box-sizing:border-box"
               oninput="filtrarUsuarios(this.value)">
        <div id="usuarioCheckList" style="display:flex;flex-direction:column;gap:6px;max-height:220px;overflow-y:auto;padding-right:4px">
          <?php
          $lastCliente = '__init__';
          foreach ($todoUsuarios as $u):
            $clienteLabel = $u['cliente_nome'] ?? 'Admins do sistema';
            if ($clienteLabel !== $lastCliente):
              $lastCliente = $clienteLabel;
          ?>
          <div class="usuario-group-label" style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.5px;padding:6px 4px 2px"><?= htmlspecialchars($clienteLabel) ?></div>
          <?php endif; ?>
          <label class="usuario-label" style="display:flex;align-items:center;gap:10px;padding:8px 12px;border:1.5px solid #e5e7eb;border-radius:9px;cursor:pointer;transition:border-color .15s;font-size:13px"
                 onmouseover="this.style.borderColor='#16a34a'" onmouseout="if(!this.querySelector('input').checked)this.style.borderColor='#e5e7eb'">
            <input type="checkbox" class="usuario-check" value="<?= $u['id'] ?>"
                   data-search="<?= htmlspecialchars(strtolower($u['nome'] . ' ' . $u['email'] . ' ' . $clienteLabel)) ?>"
                   style="width:16px;height:16px;accent-color:#16a34a;cursor:pointer;flex-shrink:0"
                   onchange="this.closest('label').style.borderColor=this.checked?'#16a34a':'#e5e7eb'">
            <div style="min-width:0">
              <div style="font-weight:600;color:#111827"><?= htmlspecialchars($u['nome']) ?></div>
              <div style="font-size:11px;color:#9ca3af"><?= htmlspecialchars($u['email']) ?></div>
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
  </div>
</div>


<!-- ════════════════════════════════════════════════════════
     SCRIPTS
  ══════════════════════════════════════════════════════════ -->
<script>
const CSRF      = <?= json_encode(Auth::csrfToken()) ?>;
const BASE_PATH = <?= json_encode(BASE_PATH) ?>;
const ESTADO_UF = <?= json_encode($uf) ?>;

document.addEventListener('DOMContentLoaded', function() {
  renderDashboards();
});

/* ─── Selecionar projeto ─── */
function selecionarProjeto(id, nome) {
  var btn = document.getElementById('btn-select-' + id);
  if (btn) { btn.classList.add('loading'); btn.textContent = '…'; }
  fetch(BASE_PATH + '/projetos/selecionar', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: '_token=' + encodeURIComponent(CSRF) + '&projeto_id=' + encodeURIComponent(id)
  })
  .then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.ok) {
      showToast('Projeto "' + nome + '" selecionado!');
      setTimeout(function(){ window.location.href = data.redirect; }, 1500);
    } else {
      if (btn) { btn.classList.remove('loading'); btn.textContent = 'Selecionar'; }
      alert(data.error || 'Erro ao selecionar.');
    }
  })
  .catch(function() {
    if (btn) { btn.classList.remove('loading'); btn.textContent = 'Selecionar'; }
    alert('Erro de comunicação.');
  });
}

/* ─── Modal: abrir (novo) ─── */
function abrirModalNovo(uf) {
  document.getElementById('modalTitulo').textContent = 'Novo Projeto';
  document.getElementById('modalProjetoId').value = '';
  limparFormModal();
  renderDashboards();
  if (uf) { var fu = document.getElementById('fUf'); if (fu) fu.value = uf; }
  document.getElementById('modalOverlay').classList.add('open');
  setTimeout(function(){ document.getElementById('fNome').focus(); }, 200);
}

/* ─── Modal: abrir (editar) ─── */
function abrirModalEditar(id) {
  document.getElementById('modalTitulo').textContent = 'Editar Projeto';
  document.getElementById('modalProjetoId').value = id;
  document.getElementById('modalErro').classList.remove('show');
  fetch(BASE_PATH + '/projetos/dados?id=' + id)
    .then(function(r){ return r.json(); })
    .then(function(p) {
      if (!p) return;
      document.getElementById('fNome').value   = p.nome || '';
      document.getElementById('fModelo').value = p.openai_model || 'gpt-4o';
      var sel = document.getElementById('fCliente'); if (sel) sel.value = p.cliente_id || '';
      var fu  = document.getElementById('fUf');      if (fu)  fu.value  = p.uf || '';
      dashboards = JSON.parse(p.dashboards_json || '[]');
      dashboards = dashboards.map(function(d){ return Object.assign({token:''}, d); });
      if (!dashboards.length) dashboards = [{ nome: 'Dashboard', url: '', icone: '📊', token: '' }];
      renderDashboards();
      var usuarioIds = p.usuario_ids || [];
      document.querySelectorAll('.usuario-check').forEach(function(cb){
        var checked = usuarioIds.indexOf(parseInt(cb.value)) !== -1;
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
  var isEx = modo === 'existente';
  document.getElementById('blocoClienteExistente').style.display = isEx ? '' : 'none';
  document.getElementById('blocoClienteNovo').style.display      = isEx ? 'none' : '';
  document.getElementById('btnModoExistente').style.background   = isEx ? '#16a34a' : 'transparent';
  document.getElementById('btnModoExistente').style.color        = isEx ? '#fff' : '#6b7280';
  document.getElementById('btnModoNovo').style.background        = isEx ? 'transparent' : '#16a34a';
  document.getElementById('btnModoNovo').style.color             = isEx ? '#6b7280' : '#fff';
  if (!isEx) setTimeout(function(){ var el = document.getElementById('fNovoClienteNome'); if (el) el.focus(); }, 50);
}

function limparFormModal() {
  ['fNome','fApiKey','fNovoClienteNome'].forEach(function(id){
    var el = document.getElementById(id); if (el) el.value = '';
  });
  var fc = document.getElementById('fCliente'); if (fc) fc.value = '';
  var fu = document.getElementById('fUf');      if (fu) fu.value = ESTADO_UF;
  var fm = document.getElementById('fModelo');  if (fm) fm.value = 'gpt-4o';
  document.getElementById('modalErro').classList.remove('show');
  dashboards = [{ nome: 'Dashboard', url: '', icone: '📊', token: '' }];
  setModoCliente('existente');
  document.querySelectorAll('.usuario-check').forEach(function(cb){
    cb.checked = false; cb.closest('label').style.borderColor = '#e5e7eb';
  });
  var us = document.getElementById('usuarioSearch'); if (us) { us.value = ''; filtrarUsuarios(''); }
}

/* ─── Dashboards ─── */
var dashboards = [{ nome: 'Dashboard', url: '', icone: '📊', token: '' }];
var iconeOpcoes = ['📊','📈','🗺️','🏛️','📋','🔍','⚡','🏅'];

function toggleDashToken(i) {
  dashboards[i]._showToken = !dashboards[i]._showToken;
  if (!dashboards[i]._showToken) dashboards[i].token = '';
  renderDashboards();
  if (dashboards[i]._showToken) {
    var inputs = document.querySelectorAll('#dashList .dash-item');
    var pw = inputs[i] && inputs[i].querySelector('input[type=password]');
    if (pw) pw.focus();
  }
}

function renderDashboards() {
  var html = dashboards.map(function(d, i) {
    var opcoesHTML = iconeOpcoes.map(function(ic){ return '<option value="' + ic + '"' + (d.icone === ic ? ' selected' : '') + '>' + ic + '</option>'; }).join('');
    var showToken = d._showToken || !!(d.token);
    var tokenRow = showToken
      ? '<div class="dash-token-row"><span style="font-size:11px;color:#6b7280;white-space:nowrap">Token:</span>' +
        '<input type="password" placeholder="Cole o token" value="' + escHtml(d.token || '') + '" style="font-family:monospace;flex:1" oninput="dashboards[' + i + '].token = this.value">' +
        '<button type="button" class="dash-token-clear" onclick="toggleDashToken(' + i + ')" title="Remover">✕</button></div>' : '';
    return '<div class="dash-item"><div style="display:flex;gap:8px;align-items:center">' +
      '<input type="text" placeholder="Nome" value="' + escHtml(d.nome) + '" style="flex:1" oninput="dashboards[' + i + '].nome = this.value">' +
      '<select onchange="dashboards[' + i + '].icone = this.value" style="width:52px">' + opcoesHTML + '</select>' +
      '<button class="dash-rm" type="button" onclick="removerDashboard(' + i + ')">&times;</button></div>' +
      '<div style="display:flex;gap:8px;align-items:center">' +
      '<input type="text" placeholder="URL do embed" value="' + escHtml(d.url) + '" style="flex:1" oninput="dashboards[' + i + '].url = this.value">' +
      (!showToken ? '<button type="button" class="dash-token-btn" onclick="toggleDashToken(' + i + ')">🔐</button>' : '') +
      '</div>' + tokenRow + '</div>';
  }).join('');
  document.getElementById('dashList').innerHTML = html;
}

function adicionarDashboard() {
  dashboards.push({ nome: '', url: '', icone: '📊', token: '' });
  renderDashboards();
}
function removerDashboard(i) {
  if (dashboards.length <= 1) { dashboards[0] = { nome: '', url: '', icone: '📊', token: '' }; renderDashboards(); return; }
  dashboards.splice(i, 1);
  renderDashboards();
}

function escHtml(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

/* ─── Filtro de usuários ─── */
function filtrarUsuarios(q) {
  q = q.toLowerCase().trim();
  document.querySelectorAll('#usuarioCheckList > *').forEach(function(el) {
    if (el.classList.contains('usuario-group-label')) return;
    if (!el.classList.contains('usuario-label')) return;
    var cb = el.querySelector('.usuario-check');
    el.style.display = (!q || (cb && cb.dataset.search && cb.dataset.search.includes(q))) ? '' : 'none';
  });
  document.querySelectorAll('#usuarioCheckList .usuario-group-label').forEach(function(grp) {
    var next = grp.nextElementSibling; var hasVisible = false;
    while (next && !next.classList.contains('usuario-group-label')) {
      if (next.classList.contains('usuario-label') && next.style.display !== 'none') hasVisible = true;
      next = next.nextElementSibling;
    }
    grp.style.display = hasVisible ? '' : 'none';
  });
}

/* ─── Excluir ─── */
var _excluirId = null;
function confirmarExcluir(id, nome) {
  _excluirId = id;
  document.getElementById('excluirNomeProjeto').textContent = nome;
  document.getElementById('modalExcluirOverlay').classList.add('open');
}
function fecharModalExcluir(evt) {
  if (evt && evt.target !== document.getElementById('modalExcluirOverlay')) return;
  document.getElementById('modalExcluirOverlay').classList.remove('open');
  _excluirId = null;
}
function executarExcluir() {
  if (!_excluirId) return;
  var btn = document.getElementById('btnConfirmarExcluir');
  btn.disabled = true; btn.textContent = 'Excluindo…';
  var form = document.createElement('form');
  form.method = 'POST'; form.action = BASE_PATH + '/projetos/deletar';
  form.innerHTML = '<input name="_token" value="' + escHtml(CSRF) + '"><input name="id" value="' + _excluirId + '">';
  document.body.appendChild(form);
  form.submit();
}

/* ─── Salvar projeto ─── */
function salvarProjeto() {
  var erroEl  = document.getElementById('modalErro');
  var btnSave = document.getElementById('btnSalvar');
  erroEl.classList.remove('show');

  var id     = document.getElementById('modalProjetoId').value;
  var nome   = document.getElementById('fNome').value.trim();
  var apiKey = document.getElementById('fApiKey').value.trim();
  var modelo = document.getElementById('fModelo').value;

  if (!nome) {
    erroEl.textContent = 'O nome do projeto é obrigatório.';
    erroEl.classList.add('show');
    document.getElementById('fNome').focus();
    return;
  }
  btnSave.disabled = true; btnSave.textContent = 'Salvando…';

  var selCli = document.getElementById('fCliente');
  var clientePromise;
  if (modoCliente === 'novo') {
    var novoNome = ((document.getElementById('fNovoClienteNome') || {}).value || '').trim();
    if (!novoNome) {
      erroEl.textContent = 'Informe o nome do novo cliente.';
      erroEl.classList.add('show');
      btnSave.disabled = false; btnSave.textContent = 'Salvar Projeto';
      return;
    }
    clientePromise = fetch(BASE_PATH + '/admin/clientes/ajax', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: '_token=' + encodeURIComponent(CSRF) + '&nome=' + encodeURIComponent(novoNome)
    }).then(function(r){ return r.json(); }).then(function(data) {
      if (!data.ok) throw new Error(data.error || 'Erro ao criar cliente.');
      if (selCli) { var opt = document.createElement('option'); opt.value = data.id; opt.textContent = data.nome; selCli.appendChild(opt); }
      return data.id;
    });
  } else {
    clientePromise = Promise.resolve(selCli ? selCli.value : '');
  }

  clientePromise.then(function(cliId) {
    var usuarioIds = [];
    document.querySelectorAll('.usuario-check:checked').forEach(function(cb){ usuarioIds.push(parseInt(cb.value)); });
    var uf = (document.getElementById('fUf') || {}).value || '';
    var body = new URLSearchParams({
      _token:          CSRF,
      nome:            nome,
      uf:              uf,
      openai_key:      apiKey,
      openai_model:    modelo,
      dashboards_json: JSON.stringify(dashboards),
      cliente_id:      cliId || '',
      usuario_ids:     JSON.stringify(usuarioIds)
    });
    if (id) body.append('id', id);
    return fetch(id ? BASE_PATH + '/projetos/ajax/editar' : BASE_PATH + '/projetos/ajax/criar', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function(r){ return r.json(); });
  })
  .then(function(data) {
    btnSave.disabled = false; btnSave.textContent = 'Salvar Projeto';
    if (data.ok) {
      fecharModal();
      showToast(document.getElementById('modalProjetoId').value ? 'Projeto atualizado!' : 'Projeto criado!');
      setTimeout(function(){ window.location.reload(); }, 1500);
    } else {
      erroEl.textContent = data.error || 'Erro ao salvar.';
      erroEl.classList.add('show');
    }
  })
  .catch(function(err) {
    btnSave.disabled = false; btnSave.textContent = 'Salvar Projeto';
    erroEl.textContent = err.message || 'Erro de comunicação.';
    erroEl.classList.add('show');
  });
}
</script>
