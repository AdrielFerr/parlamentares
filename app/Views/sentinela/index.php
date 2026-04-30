<?php $projetoId = $projeto['id']; ?>

<style>
/* ── Sentinela IA ── */
.main-content{display:flex;flex-direction:column;overflow:hidden;padding-bottom:0}
.sent-page{display:flex;flex-direction:column;flex:1;min-height:0;padding-bottom:24px}

.sent-header{display:flex;align-items:center;justify-content:space-between;padding:0 0 12px;flex-shrink:0}
.sent-title{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:700;color:var(--text)}
.sent-badge{background:var(--accent-light);border:1px solid var(--accent);border-radius:20px;padding:2px 9px;font-size:10px;color:var(--accent);font-weight:600;display:none}
.sent-badge.visible{display:inline-block}
.sent-actions{display:flex;gap:6px}
.sent-btn{background:#fff;border:1.5px solid var(--border);border-radius:8px;color:var(--muted);font-size:12px;padding:7px 13px;cursor:pointer;display:flex;align-items:center;gap:6px;font-family:inherit;transition:all .15s}
.sent-btn:hover{border-color:var(--accent);color:var(--accent)}
.sent-btn svg{width:12px;height:12px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round}

.sent-main{flex:1;display:flex;flex-direction:column;background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden}

/* context bar */
.ctx-bar{background:var(--accent-light);border-bottom:1px solid #c6e8d8;padding:6px 16px;display:none;align-items:center;gap:7px;flex-shrink:0;flex-wrap:wrap;min-height:33px}
.ctx-bar.visible{display:flex}
.ctx-label{font-size:10px;font-weight:600;color:var(--accent-dark);flex-shrink:0}
.ctx-chip{background:#fff;border:1px solid var(--accent);border-radius:20px;padding:2px 9px;font-size:10px;color:var(--accent-dark);font-weight:500}

/* messages */
.chat-area{flex:1;overflow-y:auto;padding:20px 18px;display:flex;flex-direction:column;gap:14px}
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;text-align:center;padding:40px 20px;height:100%}
.empty-icon{width:48px;height:48px;border-radius:12px;background:var(--accent-light);display:flex;align-items:center;justify-content:center}
.empty-icon svg{width:22px;height:22px;stroke:var(--accent);fill:none;stroke-width:2;stroke-linecap:round}
.empty-state h3{font-size:14px;font-weight:600;color:var(--muted)}
.empty-state p{font-size:12px;line-height:1.6;max-width:270px;color:var(--muted)}
.suggestions{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:4px}
.sug{background:var(--bg);border:1.5px solid var(--border);border-radius:20px;padding:5px 13px;font-size:11px;color:var(--muted);cursor:pointer;font-family:inherit;transition:all .15s}
.sug:hover{background:var(--accent-light);color:var(--accent);border-color:var(--accent)}

.msg-row{display:flex;gap:8px;align-items:flex-start}
.msg-row.user{flex-direction:row-reverse}
.av{width:27px;height:27px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;flex-shrink:0;margin-top:2px}
.av.assistant{background:var(--accent-light);color:var(--accent);border:1px solid #b6ddc8}
.av.user{background:#f3f4f6;color:var(--muted);border:1px solid var(--border)}
.bubble-wrap{max-width:78%;display:flex;flex-direction:column;gap:4px}
.bubble{padding:11px 15px;border-radius:14px;font-size:14px;line-height:1.65;word-wrap:break-word}
.bubble.assistant{background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:3px 14px 14px 14px}
.bubble.user{background:var(--accent);color:#fff;border-radius:14px 3px 14px 14px}
.bubble p{margin-bottom:7px}.bubble p:last-child{margin-bottom:0}
.bubble strong{font-weight:600}.bubble em{font-style:italic}
.bubble ul{margin:5px 0 7px 18px}.bubble li{margin-bottom:3px}
.msg-time{font-size:10px;color:var(--muted)}

.status-line{font-size:12px;color:var(--muted);padding:3px 10px;background:var(--bg);border-radius:6px;border:1px solid var(--border);align-self:center;display:inline-block}

.typing-row{display:flex;gap:8px;align-items:flex-start}
.typing-bub{background:var(--bg);border:1px solid var(--border);border-radius:3px 14px 14px 14px;padding:12px 14px;display:flex;gap:4px;align-items:center}
.tdot{width:6px;height:6px;border-radius:50%;background:var(--muted);animation:tblink 1.2s ease infinite}
.tdot:nth-child(2){animation-delay:.2s}.tdot:nth-child(3){animation-delay:.4s}
@keyframes tblink{0%,80%,100%{opacity:.2}40%{opacity:1}}

.chart-bubble{background:var(--bg);border:1px solid var(--border);border-radius:14px;padding:16px;max-width:560px;width:100%}
.chart-bubble canvas{max-height:260px}
.dl-btn{margin-top:10px;padding:7px 16px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s}
.dl-btn:hover{background:var(--accent-dark)}

.input-bar{border-top:1px solid var(--border);padding:12px 14px;flex-shrink:0}
.input-row{display:flex;gap:8px;align-items:flex-end}
.sent-textarea{flex:1;padding:11px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;font-family:inherit;resize:none;outline:none;line-height:1.5;height:44px;max-height:160px;overflow-y:auto;background:var(--bg);box-sizing:border-box;display:block;width:auto;margin-bottom:0}
.sent-textarea:focus{border-color:var(--accent);background:#fff}
.send-btn{width:44px;height:44px;background:var(--accent);color:#fff;border:none;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .2s}
.send-btn:hover{background:var(--accent-dark)}
.send-btn:disabled{opacity:.45;cursor:default}
.send-btn svg{width:16px;height:16px;stroke:white;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}

/* Modals */
.overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;z-index:300}
.overlay.hidden{display:none}
.modal-box{background:#fff;border:1px solid var(--border);border-radius:16px;padding:24px;width:370px;max-width:92vw;box-shadow:0 20px 60px rgba(0,0,0,.12)}
.modal-title{font-size:15px;font-weight:700;color:var(--text);margin-bottom:4px}
.modal-sub{font-size:12px;color:var(--muted);margin-bottom:16px;line-height:1.55}
.dropzone{border:2px dashed var(--border);border-radius:10px;padding:26px 16px;text-align:center;cursor:pointer;transition:all .2s}
.dropzone:hover,.dropzone.drag{border-color:var(--accent);background:var(--accent-light)}
.drop-em{font-size:28px;margin-bottom:8px}
.drop-t{font-size:13px;color:var(--text);font-weight:500}
.drop-s{font-size:11px;color:var(--muted);margin-top:3px}
.modal-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
.mbtn{background:none;border:1.5px solid var(--border);border-radius:8px;color:var(--muted);font-size:12px;padding:7px 16px;cursor:pointer;font-family:inherit;transition:all .15s}
.mbtn:hover{background:var(--bg)}
.viewer-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px}
.viewer-item:hover{background:var(--bg)}
.vname{flex:1;font-size:12px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.vsize{font-size:10px;color:var(--muted);margin-top:1px}
.vbtn{background:none;border:1.5px solid var(--border);border-radius:6px;color:var(--muted);font-size:10px;padding:4px 9px;cursor:pointer;flex-shrink:0;font-family:inherit}
.vbtn:hover{border-color:var(--accent);color:var(--accent)}
.vbtn.del:hover{border-color:var(--red);color:var(--red)}
.file-type-badge{width:32px;height:36px;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:8px;font-weight:700;flex-shrink:0}
</style>

<div class="sent-page">

  <!-- Header -->
  <div class="sent-header">
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:30px;height:30px;background:var(--accent);border-radius:8px;display:flex;align-items:center;justify-content:center">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l2 2"/></svg>
      </div>
      <span class="sent-title">Sentinela IA</span>
      <span class="sent-badge" id="fileBadge"></span>
    </div>
    <div class="sent-actions">
      <button class="sent-btn" onclick="document.getElementById('fileInput').click()">
        <svg viewBox="0 0 16 16"><path d="M8 2v12M2 8h12"/></svg>
        Anexar pesquisa
      </button>
      <button class="sent-btn" onclick="openViewer()">
        <svg viewBox="0 0 16 16"><rect x="2" y="2" width="12" height="14" rx="1"/><path d="M5 6h6M5 9h6M5 12h4"/></svg>
        Ver pesquisas
      </button>
      <button class="sent-btn" onclick="if(confirm('Limpar toda a conversa?')) clearHistory()" title="Nova conversa">
        <svg viewBox="0 0 16 16"><path d="M3 3l10 10M13 3L3 13"/></svg>
        Nova conversa
      </button>
    </div>
  </div>

  <!-- Chat -->
  <div class="sent-main">
    <!-- Context bar -->
    <div class="ctx-bar" id="ctxBar">
      <span class="ctx-label">Contexto:</span>
      <div id="ctxChips" style="display:flex;gap:5px;flex-wrap:wrap"></div>
    </div>

    <!-- Messages -->
    <div class="chat-area" id="chatArea">
    </div>

    <!-- Input -->
    <div class="input-bar">
      <div class="input-row">
        <textarea id="userInput" class="sent-textarea" placeholder="Faça uma pergunta sobre as pesquisas..."></textarea>
        <button class="send-btn" id="sendBtn" onclick="sendMsg()">
          <svg viewBox="0 0 16 16"><path d="M14 2L2 8l5 2M14 2L9 14l-2-4"/></svg>
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Upload -->
<div class="overlay hidden" id="uploadModal">
  <div class="modal-box">
    <div class="modal-title">Anexar pesquisa</div>
    <div class="modal-sub">PDF, DOCX ou TXT. O conteúdo é enviado ao agente como contexto.</div>
    <div class="dropzone" id="dz" onclick="document.getElementById('fileInput').click()">
      <div class="drop-em">📄</div>
      <div class="drop-t">Arraste ou clique para selecionar</div>
      <div class="drop-s">PDF · DOCX · TXT · CSV</div>
    </div>
    <div class="modal-foot">
      <button class="mbtn" onclick="closeModal('uploadModal')">Fechar</button>
    </div>
  </div>
</div>

<!-- Modal: Viewer -->
<div class="overlay hidden" id="viewerModal">
  <div class="modal-box">
    <div class="modal-title">Pesquisas anexadas</div>
    <div class="modal-sub">Arquivos carregados como contexto para o agente.</div>
    <div id="viewerList"></div>
    <div class="modal-foot">
      <button class="mbtn" onclick="closeModal('viewerModal')">Fechar</button>
    </div>
  </div>
</div>

<input type="file" id="fileInput" accept=".txt,.pdf,.docx,.csv" multiple style="display:none">

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/mammoth@1.6.0/mammoth.browser.min.js"></script>
<script>
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js';

const PROJETO_ID = <?= $projetoId ?>;
const OPENAI_URL = '<?= BASE_PATH ?>/api/openai';
const CSRF_TOKEN = '<?= Auth::csrfToken() ?>';

// files: id -> {name, content, chars}
let files = {};
let nextLocalId = 1;
let busy = false;
let conversationHistory = [];
const MAX_HISTORY_TURNS = 20;
const HISTORY_KEY = 'sentinela_history_' + PROJETO_ID;

function saveHistory() {
  try { sessionStorage.setItem(HISTORY_KEY, JSON.stringify(conversationHistory)); } catch(e) {}
}

function clearHistory() {
  conversationHistory = [];
  try { sessionStorage.removeItem(HISTORY_KEY); } catch(e) {}
  const ca = document.getElementById('chatArea');
  ca.innerHTML = '';
  ca.appendChild(emptyStateEl());
}

function emptyStateEl() {
  const d = document.createElement('div');
  d.className = 'empty-state';
  d.id = 'emptyState';
  d.innerHTML = `<div class="empty-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 2.5"/></svg></div>
    <h3>Sentinela pronto</h3>
    <p>Anexe pesquisas e faça perguntas ao agente. Posso gerar gráficos se você pedir.</p>
    <div class="suggestions">
      <button class="sug" onclick="useSug(this)">Intenção de votos</button>
      <button class="sug" onclick="useSug(this)">Compare os institutos</button>
      <button class="sug" onclick="useSug(this)">Perfil do eleitorado</button>
      <button class="sug" onclick="useSug(this)">Gerar gráfico de resultados</button>
      <button class="sug" onclick="useSug(this)">Metodologia da pesquisa</button>
    </div>`;
  return d;
}

function loadHistory() {
  try {
    const raw = sessionStorage.getItem(HISTORY_KEY);
    if (!raw) return;
    const history = JSON.parse(raw);
    if (!Array.isArray(history) || !history.length) return;
    conversationHistory = history;
    history.forEach(msg => {
      if (msg.role === 'user') {
        addMsg('user', '<p>' + esc(msg.content) + '</p>');
      } else if (msg.content.startsWith('[Gráfico gerado:')) {
        addMsg('assistant', '<p><em>' + esc(msg.content) + '</em></p>');
      } else {
        addMsg('assistant', fmt(cleanFileRefs(msg.content)));
      }
    });
  } catch(e) {}
}

// Seed from DB
<?php foreach ($arquivos as $a): ?>
files[<?= $a['id'] ?>] = {name: <?= json_encode($a['nome']) ?>, content: <?= json_encode($a['conteudo']) ?>, chars: <?= strlen($a['conteudo']) ?>};
<?php endforeach; ?>

// ── File input ──
document.getElementById('fileInput').addEventListener('change', e => {
  [...e.target.files].forEach(processFile);
  e.target.value = '';
  closeModal('uploadModal');
});

// ── Drag & drop on dropzone ──
const dz = document.getElementById('dz');
dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag'); });
dz.addEventListener('dragleave', () => dz.classList.remove('drag'));
dz.addEventListener('drop', e => { e.preventDefault(); dz.classList.remove('drag'); [...e.dataTransfer.files].forEach(processFile); closeModal('uploadModal'); });

async function processFile(file) {
  const ext = file.name.split('.').pop().toLowerCase();
  let text = '';
  try {
    if (ext === 'pdf') text = await extractPdf(file);
    else if (ext === 'docx') text = await extractDocx(file);
    else text = await file.text();
  } catch(e) {
    addStatus('❌ ' + file.name + ' — erro ao ler o arquivo.');
    return;
  }

  if (!text || text.trim().length < 5) {
    addStatus('⚠️ ' + file.name + ' — não foi possível extrair texto.');
    return;
  }

  const content = text.trim().slice(0, 50000);
  const id = 'local_' + nextLocalId++;
  files[id] = {name: file.name, content, chars: content.length};
  renderCtx();
  addStatus('✅ ' + file.name + ' — ' + content.length.toLocaleString('pt-BR') + ' caracteres extraídos.');

  // Persist
  const fd = new FormData();
  fd.append('_token', CSRF_TOKEN);
  fd.append('projeto_id', PROJETO_ID);
  fd.append('nome', file.name);
  fd.append('conteudo', content);
  fetch('<?= BASE_PATH ?>/api/arquivo', {method:'POST', body:fd}).catch(()=>{});
}

async function extractPdf(file) {
  const buf = await file.arrayBuffer();
  const pdf = await pdfjsLib.getDocument({data: buf}).promise;
  let out = '';
  for (let i = 1; i <= pdf.numPages; i++) {
    const page = await pdf.getPage(i);
    const tc = await page.getTextContent();
    out += tc.items.map(s => s.str).join(' ') + '\n';
  }
  return out;
}

async function extractDocx(file) {
  const buf = await file.arrayBuffer();
  const res = await mammoth.extractRawText({arrayBuffer: buf});
  return res.value;
}

// ── Context bar ──
function renderCtx() {
  const keys = Object.keys(files);
  const bar = document.getElementById('ctxBar');
  const chips = document.getElementById('ctxChips');
  const badge = document.getElementById('fileBadge');

  if (!keys.length) {
    bar.classList.remove('visible');
    badge.classList.remove('visible');
    return;
  }

  bar.classList.add('visible');
  badge.textContent = keys.length + ' pesquisa' + (keys.length !== 1 ? 's' : '');
  badge.classList.add('visible');
  chips.innerHTML = keys.map(k => `<span class="ctx-chip">${esc(files[k].name)}</span>`).join('');
}

// ── Modals ──
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

document.querySelectorAll('.overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.add('hidden'); });
});

function openViewer() {
  const list = document.getElementById('viewerList');
  const keys = Object.keys(files);
  if (!keys.length) {
    list.innerHTML = '<div style="font-size:12px;color:var(--muted);text-align:center;padding:20px;">Nenhuma pesquisa anexada ainda.</div>';
  } else {
    list.innerHTML = keys.map(k => {
      const f = files[k];
      const ext = f.name.split('.').pop().toLowerCase();
      const colors = {pdf:['#fef2f2','#dc2626','#991b1b'], docx:['#eff6ff','#2563eb','#1e40af']};
      const [bg, color, bd] = colors[ext] || ['#f0fdf4','#16a34a','#15803d'];
      return `<div class="viewer-item">
        <div class="file-type-badge" style="background:${bg};color:${color};border:1px solid ${bd}">${ext.toUpperCase()}</div>
        <div style="flex:1;min-width:0">
          <div class="vname" title="${esc(f.name)}">${esc(f.name)}</div>
          <div class="vsize">${f.chars.toLocaleString('pt-BR')} caracteres</div>
        </div>
        <button class="vbtn del" onclick="removeFile('${k}');openViewer();">Remover</button>
      </div>`;
    }).join('');
  }
  document.getElementById('viewerModal').classList.remove('hidden');
}

function removeFile(id) {
  delete files[id];
  renderCtx();
  if (!String(id).startsWith('local_')) {
    const fd = new FormData();
    fd.append('_token', CSRF_TOKEN);
    fd.append('id', id);
    fetch('<?= BASE_PATH ?>/api/arquivo/remover', {method:'POST', body:fd}).catch(()=>{});
  }
}

// ── Chat ──
const chatArea = document.getElementById('chatArea');
const inputEl  = document.getElementById('userInput');

chatArea.appendChild(emptyStateEl());
renderCtx();
loadHistory();

inputEl.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); } });
inputEl.addEventListener('input', () => { inputEl.style.height = '44px'; inputEl.style.height = Math.min(inputEl.scrollHeight, 160) + 'px'; });

function useSug(el) {
  inputEl.value = el.textContent;
  inputEl.dispatchEvent(new Event('input'));
  inputEl.focus();
}

function hideEmpty() {
  const es = document.getElementById('emptyState');
  if (es) es.style.display = 'none';
}

function addStatus(msg) {
  hideEmpty();
  const div = document.createElement('div');
  div.className = 'status-line';
  div.textContent = msg;
  chatArea.appendChild(div);
  chatArea.scrollTop = chatArea.scrollHeight;
}

function addMsg(role, html) {
  hideEmpty();
  const time = new Date().toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
  const row = document.createElement('div');
  row.className = 'msg-row ' + role;
  const av = `<div class="av ${role}">${role === 'assistant' ? 'IA' : 'EU'}</div>`;
  row.innerHTML = av + `<div class="bubble-wrap"><div class="bubble ${role}">${html}</div><span class="msg-time">${time}</span></div>`;
  chatArea.appendChild(row);
  chatArea.scrollTop = chatArea.scrollHeight;
}

function addTyping() {
  hideEmpty();
  const row = document.createElement('div');
  row.className = 'typing-row';
  row.id = 'typingRow';
  row.innerHTML = '<div class="av assistant">IA</div><div class="typing-bub"><div class="tdot"></div><div class="tdot"></div><div class="tdot"></div></div>';
  chatArea.appendChild(row);
  chatArea.scrollTop = chatArea.scrollHeight;
}

function removeTyping() { document.getElementById('typingRow')?.remove(); }

function fmt(text) {
  let s = text
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>')
    .replace(/\*(.*?)\*/g,'<em>$1</em>')
    .replace(/^[\-\*] (.+)/gm, '<li>$1</li>');
  // wrap li in ul
  s = s.replace(/(<li>.*<\/li>)/gs, m => '<ul>' + m + '</ul>');
  return s.split(/\n\n+/).map(p => p.startsWith('<ul>') ? p : '<p>' + p.replace(/\n/g,'<br>') + '</p>').join('');
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function cleanFileRefs(text) {
  return text.replace(/\s*\([A-Za-z0-9_\-\s\.]+\.(txt|pdf|docx|csv|xlsx)\)/gi, '');
}

function tryParseChart(text) {
  const t = text.trim();
  if (t.startsWith('{')) { try { const d = JSON.parse(t); if (d.type === 'chart') return d; } catch(e){} }
  const mb = t.match(/```(?:json)?\s*(\{[\s\S]*?\})\s*```/);
  if (mb) { try { const d = JSON.parse(mb[1]); if (d.type === 'chart') return d; } catch(e){} }
  const ib = t.match(/(\{"type":"chart"[\s\S]*?\})/);
  if (ib) { try { const d = JSON.parse(ib[1]); if (d.type === 'chart') return d; } catch(e){} }
  return null;
}

function addChartMsg(d) {
  hideEmpty();
  const time = new Date().toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'});
  const row = document.createElement('div');
  row.className = 'msg-row assistant';

  const bubble = document.createElement('div');
  bubble.className = 'chart-bubble';

  if (d.title) {
    const h = document.createElement('div');
    h.style.cssText = 'font-size:14px;font-weight:700;margin-bottom:12px;color:var(--text)';
    h.textContent = d.title;
    bubble.appendChild(h);
  }

  const canvas = document.createElement('canvas');
  bubble.appendChild(canvas);

  const colors = ['#1A6B4F','#C9A84C','#3B82F6','#EF4444','#8B5CF6','#F97316'];
  new Chart(canvas, {
    type: d.chartType || 'bar',
    data: {
      labels: d.labels || [],
      datasets: (d.datasets || []).map((ds, i) => ({
        label: ds.label || '',
        data: ds.data || [],
        backgroundColor: d.chartType === 'doughnut' || d.chartType === 'pie'
          ? (d.labels || []).map((_, j) => colors[j % colors.length])
          : colors[i % colors.length],
        borderRadius: 4,
      }))
    },
    options: {
      responsive: true,
      plugins: {legend: {position: 'bottom'}, tooltip: {callbacks: {label: ctx => ' ' + ctx.formattedValue + '%'}}}
    }
  });

  const dl = document.createElement('button');
  dl.className = 'dl-btn';
  dl.textContent = '⬇ Baixar gráfico PNG';
  dl.onclick = () => {
    const a = document.createElement('a');
    a.href = canvas.toDataURL('image/png');
    a.download = (d.title || 'grafico') + '.png';
    a.click();
  };
  bubble.appendChild(dl);

  row.innerHTML = '<div class="av assistant">IA</div>';
  const wrap = document.createElement('div');
  wrap.className = 'bubble-wrap';
  wrap.appendChild(bubble);
  const ts = document.createElement('span');
  ts.className = 'msg-time';
  ts.textContent = time;
  wrap.appendChild(ts);
  row.appendChild(wrap);
  chatArea.appendChild(row);
  chatArea.scrollTop = chatArea.scrollHeight;
}

async function sendMsg() {
  const q = inputEl.value.trim();
  if (!q || busy) return;
  busy = true;
  document.getElementById('sendBtn').disabled = true;

  addMsg('user', '<p>' + esc(q) + '</p>');
  inputEl.value = ''; inputEl.style.height = '';
  addTyping();

  conversationHistory.push({role: 'user', content: q});

  const docs = Object.values(files).map(f => '[' + f.name + ']\n' + f.content).join('\n\n---\n\n');
  const sysContent = `Você é o Sentinela IA, assistente especializado exclusivamente em análise de pesquisas eleitorais e de opinião pública.

REGRAS OBRIGATÓRIAS:
1. Responda APENAS sobre o conteúdo dos documentos de pesquisa carregados. Não responda sobre outros temas.
2. Se o usuário perguntar algo não relacionado a pesquisas eleitorais ou aos documentos carregados, recuse educadamente e peça que ele carregue uma pesquisa relevante.
3. Não desvie o assunto para política partidária, aconselhamento estratégico, criação de conteúdo ou temas além das pesquisas carregadas.
4. Nunca mencione nomes de arquivos nem coloque referências entre parênteses nas suas respostas.
5. Não cite de qual arquivo o dado foi retirado — apenas forneça a informação diretamente.
6. Responda sempre em português brasileiro de forma clara e objetiva.
7. Temas permitidos: metodologia de pesquisa, intenção de votos, aprovação/rejeição, perfil do eleitorado, análise estatística, tendências eleitorais, comparação entre pesquisas carregadas, ranking de candidatos conforme as pesquisas.
8. Temas proibidos: opiniões pessoais, aconselhamento eleitoral, conteúdo político não baseado nas pesquisas, qualquer assunto fora do escopo das pesquisas carregadas.
9. Você TEM MEMÓRIA desta conversa — lembre-se de tudo que foi dito anteriormente, incluindo gráficos que você gerou.
Se o usuário pedir um gráfico, retorne APENAS um JSON válido neste formato exato, sem nenhum texto antes ou depois:
{"type":"chart","chartType":"bar","title":"Título do gráfico","labels":["A","B"],"datasets":[{"label":"Série","data":[10,20]}]}
chartType pode ser: bar, horizontalBar, line, doughnut, pie.

${docs ? 'Documentos de pesquisa disponíveis:\n\n' + docs : 'Nenhum documento carregado. Oriente o usuário a anexar uma pesquisa eleitoral para começar.'}`;

  // Limita o histórico para não estourar o contexto da API (mantém últimas N trocas)
  while (conversationHistory.length > MAX_HISTORY_TURNS * 2) {
    conversationHistory.splice(0, 2);
  }

  const messages = [
    {role: 'system', content: sysContent},
    ...conversationHistory
  ];

  try {
    const fd = new FormData();
    fd.append('_token', CSRF_TOKEN);
    fd.append('projeto_id', PROJETO_ID);
    fd.append('messages', JSON.stringify(messages));

    const res  = await fetch(OPENAI_URL, {method:'POST', body:fd});
    const data = await res.json();
    removeTyping();

    if (data.error) {
      conversationHistory.pop();
      saveHistory();
      addMsg('assistant', '<p>⚠️ ' + esc(data.error) + '</p>');
    } else {
      const text = data.choices?.[0]?.message?.content || '';
      if (!text) {
        conversationHistory.pop();
        saveHistory();
        addMsg('assistant', '<p>⚠️ Sem resposta do servidor.</p>');
      } else {
        const chart = tryParseChart(text);
        if (chart) {
          const chartDesc = '[Gráfico gerado: "' + (chart.title || 'sem título') + '" — tipo: ' + (chart.chartType || 'bar') +
            ', categorias: ' + (chart.labels || []).join(', ') +
            ', dados: ' + (chart.datasets || []).map(ds => ds.label + ': ' + (ds.data || []).join(', ')).join(' | ') + ']';
          conversationHistory.push({role: 'assistant', content: chartDesc});
          saveHistory();
          addChartMsg(chart);
        } else {
          conversationHistory.push({role: 'assistant', content: text});
          saveHistory();
          addMsg('assistant', fmt(cleanFileRefs(text)));
        }
      }
    }
  } catch(e) {
    conversationHistory.pop();
    saveHistory();
    removeTyping();
    addMsg('assistant', '<p>⚠️ Falha na comunicação com o servidor.</p>');
  }

  busy = false;
  document.getElementById('sendBtn').disabled = false;
}
</script>
