<?php
$abas = [
    'inicio'     => 'Início (Biografia)',
    'materias'   => 'Matérias',
    'normas'     => 'Normas',
    'emendas'    => 'Emendas',
    'comissoes'  => 'Comissões',
    'frentes'    => 'Frentes',
    'filiacoes'  => 'Filiações',
    'relatorias' => 'Relatorias',
];
$abaAtual = $aba ?: 'inicio';

// Campos por aba — espelha exatamente o que aparece nas modais do sistema
$camposPorAba = [
    'inicio' => [
        ['key'=>'biografia','label'=>'Biografia','type'=>'textarea','rows'=>8],
    ],
    'materias' => [
        ['key'=>'tipo',             'label'=>'Tipo (sigla)',           'type'=>'text',   'placeholder'=>'ex: PL, PEC, PDC, MPV'],
        ['key'=>'numero',           'label'=>'Número',                 'type'=>'text'],
        ['key'=>'ano',              'label'=>'Ano',                    'type'=>'number'],
        ['key'=>'em_tramitacao',    'label'=>'Em Tramitação',          'type'=>'select', 'options'=>[''=>'Não informado','1'=>'Sim — Em tramitação','0'=>'Não — Encerrada']],
        ['key'=>'situacao',         'label'=>'Situação Atual',         'type'=>'text',   'placeholder'=>'ex: Aguardando pauta na CCJ'],
        ['key'=>'orgao_atual',      'label'=>'Órgão Atual',            'type'=>'text',   'placeholder'=>'ex: Comissão de Constituição, Justiça e Cidadania'],
        ['key'=>'regime',           'label'=>'Regime de Tramitação',   'type'=>'text',   'placeholder'=>'ex: Normal, Urgência'],
        ['key'=>'data_apresentacao','label'=>'Data de Apresentação',   'type'=>'date'],
        ['key'=>'numero_protocolo', 'label'=>'Número de Protocolo',    'type'=>'text'],
        ['key'=>'ementa',           'label'=>'Ementa',                 'type'=>'textarea','rows'=>4],
        ['key'=>'despacho_atual',   'label'=>'Último Despacho',        'type'=>'textarea','rows'=>3],
    ],
    'normas' => [
        ['key'=>'tipo',      'label'=>'Tipo (sigla)', 'type'=>'text',    'placeholder'=>'ex: Lei, Decreto, Resolução'],
        ['key'=>'numero',    'label'=>'Número',       'type'=>'text'],
        ['key'=>'ano',       'label'=>'Ano',          'type'=>'number'],
        ['key'=>'ementa',    'label'=>'Ementa',       'type'=>'textarea','rows'=>4],
        ['key'=>'data_norma','label'=>'Data de Publicação','type'=>'date'],
    ],
    'emendas' => [
        ['key'=>'numero',           'label'=>'Número da Emenda',       'type'=>'text'],
        ['key'=>'ano',              'label'=>'Ano',                    'type'=>'number'],
        ['key'=>'tipo',             'label'=>'Tipo',                   'type'=>'text',   'placeholder'=>'ex: Individual Impositiva'],
        ['key'=>'funcao',           'label'=>'Função',                 'type'=>'text',   'placeholder'=>'ex: Saúde, Educação'],
        ['key'=>'subfuncao',        'label'=>'Subfunção',              'type'=>'text',   'placeholder'=>'ex: Atenção Básica'],
        ['key'=>'orgao',            'label'=>'Órgão/Ministério',       'type'=>'text',   'placeholder'=>'ex: Ministério da Saúde'],
        ['key'=>'acao',             'label'=>'Ação Orçamentária',      'type'=>'text',   'placeholder'=>'ex: Construção de UBS'],
        ['key'=>'programa',         'label'=>'Programa',               'type'=>'text',   'placeholder'=>'ex: Saúde da Família'],
        ['key'=>'localidade',       'label'=>'Localidade/Destino',     'type'=>'text',   'placeholder'=>'ex: Fortaleza - CE'],
        ['key'=>'valor_dotacao',    'label'=>'Valor Dotação (R$)',     'type'=>'number', 'step'=>'0.01'],
        ['key'=>'valor_empenhado',  'label'=>'Valor Empenhado (R$)',   'type'=>'number', 'step'=>'0.01'],
        ['key'=>'valor_liquidado',  'label'=>'Valor Liquidado (R$)',   'type'=>'number', 'step'=>'0.01'],
        ['key'=>'valor_pago',       'label'=>'Valor Pago (R$)',        'type'=>'number', 'step'=>'0.01'],
    ],
    'comissoes' => [
        ['key'=>'comissao',    'label'=>'Nome da Comissão', 'type'=>'text', 'placeholder'=>'ex: Comissão de Constituição, Justiça e Cidadania (CCJ)'],
        ['key'=>'cargo',       'label'=>'Cargo',            'type'=>'text', 'placeholder'=>'ex: Membro Titular, Suplente, Presidente'],
        ['key'=>'data_inicio', 'label'=>'Data de Início',   'type'=>'date'],
        ['key'=>'data_fim',    'label'=>'Data de Fim',      'type'=>'date'],
    ],
    'frentes' => [
        ['key'=>'frente_nome','label'=>'Nome da Frente Parlamentar','type'=>'text'],
        ['key'=>'cargo',      'label'=>'Cargo',                     'type'=>'text', 'placeholder'=>'ex: Presidente, Vice-Presidente, Membro'],
        ['key'=>'data_entrada','label'=>'Data de Entrada',          'type'=>'date'],
        ['key'=>'data_saida', 'label'=>'Data de Saída',             'type'=>'date'],
    ],
    'filiacoes' => [
        ['key'=>'partido',         'label'=>'Partido (sigla)',    'type'=>'text', 'placeholder'=>'ex: PP, MDB, PT'],
        ['key'=>'data_filiacao',   'label'=>'Data de Filiação',   'type'=>'date'],
        ['key'=>'data_desfiliacao','label'=>'Data de Desfiliação','type'=>'date'],
    ],
    'relatorias' => [
        ['key'=>'materia',         'label'=>'Identificação da Matéria', 'type'=>'text',    'placeholder'=>'ex: PL 1847/2023'],
        ['key'=>'tipo',            'label'=>'Tipo da Matéria',          'type'=>'text',    'placeholder'=>'ex: PL, PEC, PDL'],
        ['key'=>'numero',          'label'=>'Número',                   'type'=>'text'],
        ['key'=>'ano',             'label'=>'Ano',                      'type'=>'number'],
        ['key'=>'em_tramitacao',   'label'=>'Em Tramitação',            'type'=>'select',  'options'=>[''=>'Não informado','1'=>'Sim — Em tramitação','0'=>'Não — Encerrada']],
        ['key'=>'ementa',          'label'=>'Ementa da Matéria',        'type'=>'textarea','rows'=>3],
        ['key'=>'comissao',        'label'=>'Comissão',                 'type'=>'text',    'placeholder'=>'ex: Comissão de Desenvolvimento Regional'],
        ['key'=>'data_designacao', 'label'=>'Data de Designação',       'type'=>'date'],
        ['key'=>'data_destituicao','label'=>'Data de Destituição',      'type'=>'date'],
        ['key'=>'situacao',        'label'=>'Situação da Relatoria',    'type'=>'text',    'placeholder'=>'ex: Em exercício, Encerrada'],
    ],
];
$campos = $camposPorAba[$abaAtual] ?? [];
?>

<div class="page-header" style="align-items:flex-start;flex-wrap:wrap;gap:8px">
  <div>
    <h1 class="page-title">Dados Manuais</h1>
    <p style="font-size:13px;color:var(--muted);margin-top:2px">Insira dados manualmente para qualquer parlamentar e aba.</p>
  </div>
  <a href="<?= BASE_PATH ?>/admin" class="btn-sm" style="align-self:center">← Voltar</a>
</div>

<style>
.parl-combo{position:relative}
.parl-combo-input{width:100%;padding:9px 36px 9px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;font-family:inherit;box-sizing:border-box;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3C/svg%3E") no-repeat right 10px center;outline:none;transition:border-color .15s}
.parl-combo-input:focus{border-color:var(--accent)}
.parl-combo-input:disabled{background:#f3f4f6;color:var(--muted);cursor:not-allowed}
.parl-combo-list{position:absolute;top:calc(100% + 4px);left:0;right:0;background:#fff;border:1.5px solid var(--accent);border-radius:10px;max-height:260px;overflow-y:auto;z-index:400;box-shadow:0 8px 24px rgba(0,0,0,.12);display:none}
.parl-combo-item{padding:9px 14px;cursor:pointer;font-size:14px;border-bottom:1px solid var(--border);transition:background .1s}
.parl-combo-item:last-child{border-bottom:none}
.parl-combo-item:hover,.parl-combo-item.active{background:var(--accent-light);color:var(--accent)}
.parl-combo-empty{padding:12px 14px;font-size:13px;color:var(--muted);text-align:center}
</style>

<div style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:20px">
  <form method="GET" action="<?= BASE_PATH ?>/admin/extras" id="extrasFilter">
    <input type="hidden" name="sapl_id" id="parlIdHidden" value="<?= $saplId ?>">
    <div style="display:grid;grid-template-columns:1fr 1.4fr 1fr auto;gap:12px;align-items:end;flex-wrap:wrap">

      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:4px">FONTE</label>
        <select name="source" id="fonteSelect" style="width:100%;padding:9px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;font-family:inherit">
          <option value="">Selecione...</option>
          <?php foreach ($fontes as $f): ?>
            <option value="<?= htmlspecialchars($f['source_key']) ?>" <?= $source===$f['source_key']?'selected':'' ?>>
              <?= htmlspecialchars($f['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:4px">PARLAMENTAR</label>
        <div class="parl-combo" id="parlCombo">
          <input type="text"
                 id="parlSearch"
                 class="parl-combo-input"
                 placeholder="<?= $source ? 'Pesquisar parlamentar...' : 'Selecione a fonte primeiro' ?>"
                 <?= !$source ? 'disabled' : '' ?>
                 autocomplete="off"
                 value="<?php
                   if ($saplId && $parlamentar) {
                     $nome = $parlamentar['nome_parlamentar'] ?: $parlamentar['nome_completo'];
                     $sigla = $parlamentar['partido_sigla'];
                     echo htmlspecialchars($nome . ' (' . $sigla . ')');
                   }
                 ?>">
          <div class="parl-combo-list" id="parlList"></div>
        </div>
      </div>

      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:4px">ABA</label>
        <select name="aba" id="abaSelect" <?= !$saplId?'disabled':'' ?> style="width:100%;padding:9px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;font-family:inherit">
          <?php foreach ($abas as $aId => $aLabel): ?>
            <option value="<?= $aId ?>" <?= $abaAtual===$aId?'selected':'' ?>><?= $aLabel ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit" class="btn-primary" style="height:40px;opacity:0;pointer-events:none;width:0;padding:0;overflow:hidden" aria-hidden="true">Filtrar</button>
    </div>
  </form>
</div>

<script>
// Dados dos parlamentares (carregados do servidor)
const PARLS_DATA = <?= json_encode(array_map(fn($p) => [
    'id'    => (int)$p['sapl_id'],
    'nome'  => $p['nome_parlamentar'] ?: $p['nome_completo'],
    'sigla' => $p['partido_sigla'],
    'ativo' => (bool)$p['ativo'],
], $parls), JSON_UNESCAPED_UNICODE) ?>;

(function() {
  const fonteSelect  = document.getElementById('fonteSelect');
  const parlSearch   = document.getElementById('parlSearch');
  const parlList     = document.getElementById('parlList');
  const parlIdHidden = document.getElementById('parlIdHidden');
  const abaSelect    = document.getElementById('abaSelect');
  const form         = document.getElementById('extrasFilter');

  let activeIdx = -1;

  function renderList(q) {
    const term = (q || '').toLowerCase().trim();
    const filtered = term.length < 1
      ? PARLS_DATA.slice(0, 80)
      : PARLS_DATA.filter(p => p.nome.toLowerCase().includes(term) || p.sigla.toLowerCase().includes(term));

    if (!filtered.length) {
      parlList.innerHTML = '<div class="parl-combo-empty">Nenhum resultado</div>';
    } else {
      parlList.innerHTML = filtered.map((p, i) =>
        `<div class="parl-combo-item" data-id="${p.id}" data-label="${p.nome} (${p.sigla})" data-idx="${i}">
          <strong>${highlight(p.nome, term)}</strong>
          <span style="color:var(--muted);font-size:12px;margin-left:6px">${p.sigla}${!p.ativo?' · inativo':''}</span>
        </div>`
      ).join('');
      parlList.querySelectorAll('.parl-combo-item').forEach(el => {
        el.addEventListener('mousedown', e => { e.preventDefault(); selectParl(el); });
      });
    }
    activeIdx = -1;
    parlList.style.display = 'block';
  }

  function highlight(nome, term) {
    if (!term) return nome;
    const idx = nome.toLowerCase().indexOf(term);
    if (idx < 0) return nome;
    return nome.slice(0, idx) + '<mark style="background:#fef3c7;border-radius:2px">' + nome.slice(idx, idx + term.length) + '</mark>' + nome.slice(idx + term.length);
  }

  function selectParl(el) {
    parlIdHidden.value = el.dataset.id;
    parlSearch.value   = el.dataset.label;
    abaSelect.disabled = false;
    parlList.style.display = 'none';
    form.submit();
  }

  parlSearch.addEventListener('focus', () => { if (parlSearch.value) renderList(parlSearch.value); else renderList(''); });
  parlSearch.addEventListener('input', () => { parlIdHidden.value = ''; renderList(parlSearch.value); });
  parlSearch.addEventListener('blur',  () => { setTimeout(() => { parlList.style.display = 'none'; }, 150); });

  parlSearch.addEventListener('keydown', e => {
    const items = parlList.querySelectorAll('.parl-combo-item');
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      activeIdx = Math.min(activeIdx + 1, items.length - 1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      activeIdx = Math.max(activeIdx - 1, 0);
    } else if (e.key === 'Enter' && activeIdx >= 0) {
      e.preventDefault();
      selectParl(items[activeIdx]);
      return;
    } else if (e.key === 'Escape') {
      parlList.style.display = 'none';
      return;
    }
    items.forEach((el, i) => el.classList.toggle('active', i === activeIdx));
    if (activeIdx >= 0) items[activeIdx]?.scrollIntoView({ block: 'nearest' });
  });

  // Trocar fonte: reseta parlamentar e recarrega
  fonteSelect.addEventListener('change', () => {
    parlIdHidden.value = '';
    parlSearch.value   = '';
    form.submit();
  });

  // Trocar aba: submete imediatamente (só se houver parlamentar selecionado)
  abaSelect.addEventListener('change', () => {
    if (parlIdHidden.value) form.submit();
  });

  // Submissão: validar que um parlamentar foi selecionado se campo preenchido
  form.addEventListener('submit', e => {
    if (parlSearch.value.trim() && !parlIdHidden.value) {
      e.preventDefault();
      parlSearch.style.borderColor = '#ef4444';
      parlSearch.placeholder = 'Selecione um parlamentar da lista';
      parlSearch.focus();
      renderList(parlSearch.value);
    }
  });
})();
</script>

<?php if ($source && $saplId && $parlamentar): ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
  <div>
    <strong style="font-size:16px"><?= htmlspecialchars($parlamentar['nome_parlamentar'] ?: $parlamentar['nome_completo']) ?></strong>
    <span style="color:var(--muted);font-size:13px"> · <?= htmlspecialchars($parlamentar['partido_sigla']) ?> · <?= htmlspecialchars($abas[$abaAtual]) ?></span>
  </div>
  <button onclick="abrirModal()" class="btn-primary">+ Adicionar Entrada</button>
</div>

<?php if (empty($entries)): ?>
  <div style="text-align:center;padding:40px 20px;background:#fff;border:1px solid var(--border);border-radius:12px;color:var(--muted);font-size:14px">
    Nenhuma entrada manual para esta aba. Clique em "+ Adicionar Entrada" para criar.
  </div>
<?php else: ?>
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Título</th>
        <th>Dados</th>
        <th>Criado em</th>
        <th>Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($entries as $e):
        $dados = json_decode($e['dados_json'], true) ?? [];
      ?>
      <tr>
        <td style="font-weight:600;max-width:200px"><?= htmlspecialchars($e['titulo'] ?: '(sem título)') ?></td>
        <td style="max-width:340px;font-size:13px;color:var(--muted)">
          <?php foreach ($dados as $k => $v): if (!$v) continue; ?>
            <span><strong><?= htmlspecialchars($k) ?>:</strong> <?= htmlspecialchars(mb_substr((string)$v, 0, 80)) ?></span><br>
          <?php endforeach; ?>
        </td>
        <td style="font-size:12px;color:var(--muted);white-space:nowrap"><?= date('d/m/Y H:i', strtotime($e['criado_em'])) ?></td>
        <td style="white-space:nowrap">
          <button onclick="editarEntrada(<?= $e['id'] ?>, <?= htmlspecialchars(json_encode(['titulo'=>$e['titulo'],'dados'=>$dados], JSON_HEX_APOS|JSON_HEX_QUOT)) ?>)" class="btn-sm" style="font-size:12px;padding:5px 10px">Editar</button>
          <button onclick="deletarEntrada(<?= $e['id'] ?>)" class="btn-danger" style="font-size:12px;padding:5px 10px">Excluir</button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- Modal de adição/edição -->
<div id="extrasModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center;padding:20px">
  <div style="background:#fff;border-radius:14px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25)">
    <div style="background:var(--accent-light);border-radius:14px 14px 0 0;padding:16px 20px;border-bottom:1px solid rgba(26,107,79,.15)">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
        <div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <span id="modalAbaLabel" style="display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;background:var(--accent);color:#fff;letter-spacing:.04em"><?= strtoupper($abas[$abaAtual]) ?></span>
          </div>
          <h3 id="modalTitle" style="font-size:16px;font-weight:700;margin:0;color:var(--accent-dark)">Adicionar Entrada</h3>
          <div style="font-size:12px;color:var(--muted);margin-top:2px">
            <?= htmlspecialchars($parlamentar['nome_parlamentar'] ?? $parlamentar['nome_completo'] ?? '') ?>
            <?php if (!empty($parlamentar['partido_sigla'])): ?> · <?= htmlspecialchars($parlamentar['partido_sigla']) ?><?php endif; ?>
          </div>
        </div>
        <button onclick="fecharModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;flex-shrink:0">×</button>
      </div>
    </div>
    <div style="padding:20px">
      <form id="extrasForm" onsubmit="salvarEntrada(event)">
        <input type="hidden" id="entradaId" value="">

        <div style="margin-bottom:14px">
          <label style="display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:4px">TÍTULO (opcional)</label>
          <input type="text" id="campoTitulo" placeholder="Ex: Emenda Saúde 2025" style="width:100%;padding:9px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;font-family:inherit;box-sizing:border-box">
        </div>

        <?php foreach ($campos as $campo): ?>
        <div style="margin-bottom:14px">
          <label style="display:block;font-size:12px;font-weight:600;color:var(--muted);margin-bottom:4px"><?= strtoupper($campo['label']) ?></label>
          <?php if ($campo['type'] === 'textarea'): ?>
            <textarea id="campo_<?= $campo['key'] ?>" data-key="<?= $campo['key'] ?>"
                      rows="<?= $campo['rows'] ?? 4 ?>"
                      placeholder="<?= htmlspecialchars($campo['placeholder'] ?? '') ?>"
                      style="width:100%;padding:9px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;font-family:inherit;box-sizing:border-box;resize:vertical"></textarea>
          <?php elseif ($campo['type'] === 'select'): ?>
            <select id="campo_<?= $campo['key'] ?>" data-key="<?= $campo['key'] ?>"
                    style="width:100%;padding:9px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;font-family:inherit;box-sizing:border-box;background:#fff">
              <?php foreach ($campo['options'] as $val => $lbl): ?>
                <option value="<?= htmlspecialchars((string)$val) ?>"><?= htmlspecialchars($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="<?= htmlspecialchars($campo['type']) ?>"
                   id="campo_<?= $campo['key'] ?>"
                   data-key="<?= $campo['key'] ?>"
                   <?= isset($campo['step']) ? 'step="'.$campo['step'].'"' : '' ?>
                   placeholder="<?= htmlspecialchars($campo['placeholder'] ?? '') ?>"
                   style="width:100%;padding:9px 12px;border-radius:8px;border:1.5px solid var(--border);font-size:14px;font-family:inherit;box-sizing:border-box">
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
          <button type="button" onclick="fecharModal()" class="btn-sm">Cancelar</button>
          <button type="submit" class="btn-primary" id="btnSalvar">Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const EXTRAS_SOURCE  = '<?= htmlspecialchars($source) ?>';
const EXTRAS_SAPL_ID = <?= $saplId ?>;
const EXTRAS_ABA     = '<?= htmlspecialchars($abaAtual) ?>';
const EXTRAS_CSRF    = '<?= htmlspecialchars($csrf) ?>';
const EXTRAS_BASE    = '<?= htmlspecialchars(BASE_PATH) ?>';

function abrirModal(titulo='', dados={}, isEdit=false) {
  document.getElementById('modalTitle').textContent = isEdit ? 'Editar Entrada' : 'Adicionar Entrada';
  document.getElementById('entradaId').value = '';
  document.getElementById('campoTitulo').value = titulo;
  document.querySelectorAll('[data-key]').forEach(el => {
    el.value = dados[el.dataset.key] ?? '';
  });
  document.getElementById('extrasModal').style.display = 'flex';
}

function editarEntrada(id, entry) {
  document.getElementById('modalTitle').textContent = 'Editar Entrada';
  document.getElementById('entradaId').value = id;
  document.getElementById('campoTitulo').value = entry.titulo || '';
  document.querySelectorAll('[data-key]').forEach(el => {
    el.value = entry.dados[el.dataset.key] ?? '';
  });
  document.getElementById('extrasModal').style.display = 'flex';
}

function fecharModal() {
  document.getElementById('extrasModal').style.display = 'none';
}

async function salvarEntrada(e) {
  e.preventDefault();
  const btn = document.getElementById('btnSalvar');
  btn.disabled = true;
  btn.textContent = 'Salvando...';

  const id     = document.getElementById('entradaId').value;
  const titulo = document.getElementById('campoTitulo').value.trim();
  const dados  = {};
  document.querySelectorAll('[data-key]').forEach(el => {
    const v = el.value.trim();
    if (v) dados[el.dataset.key] = el.type === 'number' ? parseFloat(v) : v;
  });

  const method = id ? 'PUT' : 'POST';
  const body   = id
    ? { id: parseInt(id), titulo, dados }
    : { source_key: EXTRAS_SOURCE, sapl_id: EXTRAS_SAPL_ID, aba: EXTRAS_ABA, titulo, dados };

  const resp = await fetch(EXTRAS_BASE + '/api/extras', {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const json = await resp.json();
  if (json.ok || json.id) {
    location.reload();
  } else {
    alert(json.error || 'Erro ao salvar');
    btn.disabled = false;
    btn.textContent = 'Salvar';
  }
}

async function deletarEntrada(id) {
  if (!confirm('Excluir esta entrada?')) return;
  const resp = await fetch(EXTRAS_BASE + '/api/extras?id=' + id, { method: 'DELETE' });
  const json = await resp.json();
  if (json.ok) location.reload();
  else alert(json.error || 'Erro ao excluir');
}

// Fechar modal ao clicar fora
document.getElementById('extrasModal').addEventListener('click', function(e) {
  if (e.target === this) fecharModal();
});
</script>

<?php elseif ($source): ?>
  <p style="color:var(--muted);font-size:14px;padding:20px 0">Selecione um parlamentar para continuar.</p>
<?php else: ?>
  <p style="color:var(--muted);font-size:14px;padding:20px 0">Selecione a fonte legislativa para começar.</p>
<?php endif; ?>
