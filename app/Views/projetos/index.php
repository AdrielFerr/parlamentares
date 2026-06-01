<?php
$projetoAtivo = Auth::projetoId();

$regiaoMeta = [
    'N'  => ['label' => 'Norte',        'cor' => '#0891b2', 'bg' => '#ecfeff'],
    'NE' => ['label' => 'Nordeste',     'cor' => '#ea580c', 'bg' => '#fff7ed'],
    'CO' => ['label' => 'Centro-Oeste', 'cor' => '#7c3aed', 'bg' => '#f5f3ff'],
    'SE' => ['label' => 'Sudeste',      'cor' => '#2563eb', 'bg' => '#eff6ff'],
    'S'  => ['label' => 'Sul',          'cor' => '#16a34a', 'bg' => '#f0fdf4'],
];

$flagMap = [
    'AC' => 'Bandeira_do_Acre.svg',
    'AL' => 'Bandeira_de_Alagoas.svg',
    'AM' => 'Bandeira_do_Amazonas.svg',
    'AP' => 'Bandeira_do_Amap%C3%A1.svg',
    'BA' => 'Bandeira_da_Bahia.svg',
    'CE' => 'Bandeira_do_Cear%C3%A1.svg',
    'DF' => 'Bandeira_do_Distrito_Federal_%28Brasil%29.svg',
    'ES' => 'Bandeira_do_Esp%C3%ADrito_Santo.svg',
    'GO' => 'Bandeira_de_Goi%C3%A1s.svg',
    'MA' => 'Bandeira_do_Maranh%C3%A3o.svg',
    'MG' => 'Bandeira_de_Minas_Gerais.svg',
    'MS' => 'Bandeira_de_Mato_Grosso_do_Sul.svg',
    'MT' => 'Bandeira_de_Mato_Grosso.svg',
    'PA' => 'Bandeira_do_Par%C3%A1.svg',
    'PB' => 'Bandeira_da_Para%C3%ADba.svg',
    'PE' => 'Bandeira_de_Pernambuco.svg',
    'PI' => 'Bandeira_do_Piau%C3%AD.svg',
    'PR' => 'Bandeira_do_Paran%C3%A1.svg',
    'RJ' => 'Bandeira_do_estado_do_Rio_de_Janeiro.svg',
    'RN' => 'Bandeira_do_Rio_Grande_do_Norte.svg',
    'RO' => 'Bandeira_de_Rond%C3%B4nia.svg',
    'RR' => 'Bandeira_de_Roraima.svg',
    'RS' => 'Bandeira_do_Rio_Grande_do_Sul.svg',
    'SC' => 'Bandeira_de_Santa_Catarina.svg',
    'SE' => 'Bandeira_de_Sergipe.svg',
    'SP' => 'Bandeira_do_estado_de_S%C3%A3o_Paulo.svg',
    'TO' => 'Bandeira_do_Tocantins.svg',
];
?>
<style>
/* ── Grade de estados ── */
.est-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
@media(max-width:700px){.est-grid{grid-template-columns:1fr}}

/* ── Card de estado ── */
.est-card{background:#fff;border:1.5px solid #e5e7eb;border-radius:16px;overflow:hidden;transition:box-shadow .18s}
.est-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.08)}
.est-card-head{display:flex;align-items:center;gap:14px;padding:16px 20px}
.est-card[data-has-proj="true"] .est-card-head{cursor:pointer}
.est-card[data-has-proj="true"] .est-card-head:hover{background:#fafafa}

/* ── Bandeira ── */
.est-flag-wrap{width:64px;height:42px;border-radius:8px;overflow:hidden;border:1.5px solid #e8e8e8;flex-shrink:0;background:#f0f0f0;position:relative}
.est-flag{width:100%;height:100%;object-fit:cover;display:block}
.est-uf-text{position:absolute;bottom:0;right:0;background:rgba(0,0,0,.52);color:#fff;font-size:9px;font-weight:800;padding:1px 4px;border-top-left-radius:5px;letter-spacing:.4px;line-height:1.5}
.est-flag-wrap.flag-fallback{display:flex;align-items:center;justify-content:center;border-color:transparent}
.est-flag-wrap.flag-fallback .est-flag{display:none}
.est-flag-wrap.flag-fallback .est-uf-text{position:static;background:none;font-size:14px;font-weight:800;color:#fff;padding:0;border-radius:0;letter-spacing:.3px;line-height:1}

/* ── Título e região ── */
.est-card-title{font-size:15px;font-weight:700;color:#111827;margin-bottom:3px}
.est-reg-badge{font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;display:inline-block}

/* ── Lado direito do header ── */
.est-head-right{margin-left:auto;display:flex;align-items:center;gap:8px;flex-shrink:0}
.est-proj-count{font-size:11px;font-weight:700;background:#f3f4f6;color:#6b7280;padding:3px 8px;border-radius:20px;line-height:1.4}
.est-card-chevron{font-size:16px;color:#9ca3af;transition:transform .25s;line-height:1}
.est-card[data-expanded="true"] .est-card-chevron{transform:rotate(180deg)}

/* ── Lista de projetos (colapsável) ── */
.est-projetos{border-top:1px solid #f3f4f6;display:none}
.est-card[data-expanded="true"] .est-projetos{display:block}
.est-proj-item{display:flex;align-items:center;gap:10px;padding:11px 20px;border-bottom:1px solid #f9fafb;transition:background .12s}
.est-proj-item:last-child{border-bottom:none}
.est-proj-item:hover{background:#fafafa}
.est-proj-item.ativo{background:#f0fdf4}
.est-proj-info{flex:1;min-width:0}
.est-proj-nome{font-size:13px;font-weight:600;color:#111827;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.est-proj-meta{font-size:11px;color:#9ca3af;margin-top:1px}
.est-proj-actions{display:flex;gap:6px;flex-shrink:0}
.btn-sel{padding:5px 14px;background:#16a34a;color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;transition:background .15s;white-space:nowrap}
.btn-sel:hover{background:#15803d}
.btn-sel.loading{opacity:.6;pointer-events:none}
.btn-sel-ativo{background:#dcfce7;color:#15803d;border:1.5px solid #bbf7d0}
.btn-sel-ativo:hover{background:#bbf7d0}
.btn-ed{padding:5px 10px;background:transparent;border:1.5px solid #e5e7eb;border-radius:7px;font-size:12px;font-weight:500;color:#6b7280;cursor:pointer;transition:all .15s}
.btn-ed:hover{border-color:#9ca3af;color:#374151}
.btn-del{padding:5px 8px;background:transparent;border:1.5px solid #fecaca;border-radius:7px;color:#dc2626;cursor:pointer;transition:all .15s;display:flex;align-items:center}
.btn-del:hover{background:#fef2f2}
.btn-ver-projs{padding:5px 14px;background:var(--accent,#16a34a);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s;white-space:nowrap;display:inline-block;line-height:1.6}
.btn-ver-projs:hover{background:#15803d;color:#fff}

/* ── Estado vazio (sempre visível) ── */
.est-empty{padding:14px 20px;font-size:12px;color:#d1d5db;border-top:1px solid #f3f4f6;font-style:italic}
</style>

<div class="pg-wrap">

  <div class="pg-head">
    <div>
      <h1 class="pg-title">Projetos</h1>
      <p class="pg-sub">Selecione um projeto para começar</p>
    </div>
    <div class="actions-row">
      <div class="search-wrap">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="searchInput" class="search-input" placeholder="Buscar estado ou projeto...">
      </div>
      <?php if (Auth::isSuperAdmin()): ?>
      <button class="btn-novo" onclick="abrirModalNovo()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
        Novo Projeto
      </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($estados) && empty($projetosPorUf['_sem_estado'])): ?>
  <div class="empty-state" style="grid-column:1/-1">
    <div class="es-icon">📁</div>
    <h3>Nenhum projeto ainda</h3>
    <p>Crie o primeiro projeto para começar.</p>
    <?php if (Auth::isSuperAdmin()): ?>
    <button class="es-btn" onclick="abrirModalNovo()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
      Criar primeiro projeto
    </button>
    <?php endif; ?>
  </div>

  <?php else: ?>
  <div class="est-grid" id="cardsGrid">

    <?php foreach ($estados as $uf => $est):
      $reg     = $regiaoMeta[$est['regiao']] ?? $regiaoMeta['SE'];
      $projs   = $projetosPorUf[$uf] ?? [];
      $temProj = !empty($projs);
      if (!Auth::isSuperAdmin() && !$temProj) continue;
    ?>
    <?php $flagFile = $flagMap[$uf] ?? null; $isSA = Auth::isSuperAdmin(); ?>
    <div class="est-card" data-search="<?= htmlspecialchars(strtolower($est['nome'] . ' ' . $uf)) ?>"
         data-has-proj="<?= (!$isSA && $temProj) ? 'true' : 'false' ?>" data-expanded="false">

      <div class="est-card-head" <?= (!$isSA && $temProj) ? 'onclick="toggleCard(this)"' : '' ?>>
        <div class="est-flag-wrap<?= !$flagFile ? ' flag-fallback' : '' ?>"
             data-color="<?= $reg['cor'] ?>"
             <?= !$flagFile ? 'style="background:' . $reg['cor'] . '"' : '' ?>>
          <?php if ($flagFile): ?>
          <img class="est-flag"
               src="<?= BASE_PATH ?>/public/assets/bandeiras/<?= strtolower($uf) ?>.png"
               alt="<?= htmlspecialchars($uf) ?>" loading="lazy" onerror="estFlagError(this)">
          <?php endif; ?>
          <span class="est-uf-text"><?= htmlspecialchars($uf) ?></span>
        </div>
        <div>
          <div class="est-card-title"><?= htmlspecialchars($est['nome']) ?></div>
          <span class="est-reg-badge" style="background:<?= $reg['bg'] ?>;color:<?= $reg['cor'] ?>"><?= $reg['label'] ?></span>
        </div>
        <div class="est-head-right">
          <?php if ($temProj): ?>
          <span class="est-proj-count"><?= count($projs) ?></span>
          <?php endif; ?>
          <?php if ($isSA && $temProj): ?>
          <a href="<?= BASE_PATH ?>/projetos/estado/<?= urlencode($uf) ?>" class="btn-ver-projs">Ver projetos</a>
          <?php elseif ($temProj): ?>
          <i class="ph ph-caret-down est-card-chevron"></i>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!$isSA && $temProj): ?>
      <div class="est-projetos">
        <?php foreach ($projs as $p):
          $ativo = ((int)$p['id'] === (int)$projetoAtivo);
          $parlCount = $p['parl_count'] ?? 0;
          $clienteNome = $p['cliente_nome'] ?? null;
        ?>
        <div class="est-proj-item <?= $ativo ? 'ativo' : '' ?>"
             data-search="<?= htmlspecialchars(strtolower($p['nome'])) ?>">
          <div class="est-proj-info">
            <span class="est-proj-nome"><?= htmlspecialchars($p['nome']) ?></span>
            <span class="est-proj-meta"><?= $clienteNome ? htmlspecialchars($clienteNome) : '' ?></span>
          </div>
          <div class="est-proj-actions">
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
    <?php endforeach; ?>

    <?php /* Projetos sem UF definida (só visível para SuperAdmin) */
    if (Auth::isSuperAdmin() && !empty($projetosPorUf['_sem_estado'])): ?>
    <div class="est-card" data-search="sem estado" data-has-proj="true" data-expanded="false">
      <div class="est-card-head" onclick="toggleCard(this)">
        <div class="est-flag-wrap flag-fallback" style="background:#9ca3af" data-color="#9ca3af">
          <span class="est-uf-text">?</span>
        </div>
        <div>
          <div class="est-card-title">Sem estado definido</div>
          <span class="est-reg-badge" style="background:#f3f4f6;color:#6b7280">Configure o estado no projeto</span>
        </div>
        <div class="est-head-right">
          <span class="est-proj-count"><?= count($projetosPorUf['_sem_estado']) ?></span>
          <i class="ph ph-caret-down est-card-chevron"></i>
        </div>
      </div>
      <div class="est-projetos">
        <?php foreach ($projetosPorUf['_sem_estado'] as $p):
          $ativo = ((int)$p['id'] === (int)$projetoAtivo);
          $parlCount = $p['parl_count'] ?? 0;
          $clienteNome = $p['cliente_nome'] ?? null;
        ?>
        <div class="est-proj-item <?= $ativo ? 'ativo' : '' ?>">
          <div class="est-proj-info">
            <span class="est-proj-nome"><?= htmlspecialchars($p['nome']) ?></span>
            <span class="est-proj-meta"><?= $clienteNome ? htmlspecialchars($clienteNome) : '' ?></span>
          </div>
          <div class="est-proj-actions">
            <button class="btn-del" onclick="confirmarExcluir(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['nome'])) ?>)" title="Excluir">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
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
    </div>
    <?php endif; ?>

  </div><!-- /est-grid -->
  <?php endif; ?>

</div><!-- /pg-wrap -->


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
          <span class="hint" id="ufHint" style="display:none">Preenchido automaticamente pela fonte selecionada.</span>
        </div>
        <div class="fg" style="display:flex;align-items:flex-end;padding-bottom:4px">
          <span style="font-size:12px;color:#6b7280;line-height:1.5">
            Para fontes federais (Câmara, Senado), indica qual estado o projeto representa.<br>
            Para fontes estaduais/municipais, é preenchido automaticamente.
          </span>
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

      <!-- Usuários com acesso (somente SuperAdmin) -->
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
  /* Auto-expande o card que contém o projeto atualmente ativo */
  document.querySelectorAll('.est-proj-item.ativo').forEach(function(item) {
    var card = item.closest('.est-card');
    if (card) card.setAttribute('data-expanded', 'true');
  });
  renderDashboards();
});

/* ─── Toggle do card (expandir / recolher) ─── */
function toggleCard(headEl) {
  var card = headEl.closest('.est-card');
  if (!card || card.getAttribute('data-has-proj') !== 'true') return;
  var expanded = card.getAttribute('data-expanded') === 'true';
  card.setAttribute('data-expanded', expanded ? 'false' : 'true');
}

/* ─── Fallback de bandeira ─── */
function estFlagError(img) {
  var wrap = img.closest('.est-flag-wrap');
  if (!wrap) return;
  img.style.display = 'none';
  wrap.classList.add('flag-fallback');
  wrap.style.background = wrap.dataset.color || '#9ca3af';
  wrap.style.borderColor = 'transparent';
}

/* ─── Busca (filtro client-side) ─── */
document.getElementById('searchInput').addEventListener('input', function() {
  const q = this.value.toLowerCase().trim();
  document.querySelectorAll('.est-card').forEach(function(card) {
    const stateSearch  = card.dataset.search || '';
    const stateMatches = !q || stateSearch.includes(q);

    let anyProjMatch = false;
    card.querySelectorAll('.est-proj-item').forEach(function(item) {
      const projSearch = item.dataset.search || '';
      const match = stateMatches || projSearch.includes(q);
      item.style.display = match ? '' : 'none';
      if (match) anyProjMatch = true;
    });

    const visible = stateMatches || anyProjMatch;
    card.style.display = visible ? '' : 'none';

    /* Expande automaticamente quando houver busca com projeto encontrado */
    if (card.getAttribute('data-has-proj') === 'true') {
      if (q && anyProjMatch) {
        card.setAttribute('data-expanded', 'true');
      } else if (!q) {
        /* Ao limpar a busca, recolhe — exceto o que tem projeto ativo */
        const hasActive = !!card.querySelector('.est-proj-item.ativo');
        card.setAttribute('data-expanded', hasActive ? 'true' : 'false');
      }
    }
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
function abrirModalNovo(uf) {
  document.getElementById('modalTitulo').textContent = 'Novo Projeto';
  document.getElementById('modalProjetoId').value = '';
  limparFormModal();
  renderDashboards();
  /* Pré-seleciona o estado quando chamado pelo botão "+" de um card */
  if (uf) {
    var fusel = document.getElementById('fUf');
    if (fusel) fusel.value = uf;
  }
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
      document.getElementById('fModelo').value  = p.openai_model || 'gpt-4o';
      /* Cliente (super admin) */
      const sel = document.getElementById('fCliente');
      if (sel) sel.value = p.cliente_id || '';
      /* UF */
      const fusel = document.getElementById('fUf');
      if (fusel) fusel.value = p.uf || '';
      /* Dashboards */
      dashboards = JSON.parse(p.dashboards_json || '[]');
      dashboards = dashboards.map(function(d){ return Object.assign({token:''}, d); });
      if (!dashboards.length) dashboards = [{ nome: 'Dashboard', url: '', icone: '📊', token: '' }];
      renderDashboards();
      /* Usuários com acesso */
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
  ['fNome','fApiKey','fNovoClienteNome'].forEach(function(id){
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  const fc = document.getElementById('fCliente'); if (fc) fc.value = '';
  const fu = document.getElementById('fUf');      if (fu) fu.value = '';
  const fm = document.getElementById('fModelo');  if (fm) fm.value = 'gpt-4o';
  document.getElementById('modalErro').classList.remove('show');
  dashboards = [{ nome: 'Dashboard', url: '', icone: '📊' }];
  setModoCliente('existente');
  document.querySelectorAll('.usuario-check').forEach(function(cb){
    cb.checked = false;
    cb.closest('label').style.borderColor = '#e5e7eb';
  });
  var us = document.getElementById('usuarioSearch');
  if (us) { us.value = ''; filtrarUsuarios(''); }
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

/* ─── Filtro de usuários no modal ─── */
function filtrarUsuarios(q) {
  q = q.toLowerCase().trim();
  var lastGroup = null;
  document.querySelectorAll('#usuarioCheckList > *').forEach(function(el) {
    if (el.classList.contains('usuario-group-label')) {
      lastGroup = el;
      return;
    }
    if (!el.classList.contains('usuario-label')) return;
    var cb = el.querySelector('.usuario-check');
    var match = !q || (cb && cb.dataset.search && cb.dataset.search.includes(q));
    el.style.display = match ? '' : 'none';
  });
  /* Esconde labels de grupo sem itens visíveis */
  var groups = document.querySelectorAll('#usuarioCheckList .usuario-group-label');
  groups.forEach(function(grp) {
    var next = grp.nextElementSibling;
    var hasVisible = false;
    while (next && !next.classList.contains('usuario-group-label')) {
      if (next.classList.contains('usuario-label') && next.style.display !== 'none') hasVisible = true;
      next = next.nextElementSibling;
    }
    grp.style.display = hasVisible ? '' : 'none';
  });
}

/* ─── Excluir projeto ─── */
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
  const btn = document.getElementById('btnConfirmarExcluir');
  btn.disabled = true;
  btn.textContent = 'Excluindo…';
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = BASE_PATH + '/projetos/deletar';
  form.innerHTML =
    '<input name="_token" value="' + escHtml(CSRF) + '">' +
    '<input name="id" value="' + _excluirId + '">';
  document.body.appendChild(form);
  form.submit();
}

/* ─── Salvar projeto (AJAX) ─── */
function salvarProjeto() {
  const erroEl  = document.getElementById('modalErro');
  const btnSave = document.getElementById('btnSalvar');
  erroEl.classList.remove('show');

  const id     = document.getElementById('modalProjetoId').value;
  const nome   = document.getElementById('fNome').value.trim();
  const apiKey = document.getElementById('fApiKey').value.trim();
  const modelo = document.getElementById('fModelo').value;

  if (!nome) {
    erroEl.textContent = 'O nome do projeto é obrigatório.';
    erroEl.classList.add('show');
    document.getElementById('fNome').focus();
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
    var usuarioIds = [];
    document.querySelectorAll('.usuario-check:checked').forEach(function(cb){ usuarioIds.push(parseInt(cb.value)); });

    const uf = (document.getElementById('fUf') || {}).value || '';
    const body = new URLSearchParams({
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
