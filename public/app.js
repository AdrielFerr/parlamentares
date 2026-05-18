/**
 * Parlamentares · app.js v4
 * Grupos por tipo, dashboard filtros, agente IA, bio formatada
 */

const PROXY_BASE = APP_CONFIG.proxyBase;
const API_BASE   = APP_CONFIG.saplBaseUrl;

let allParlamentares=[], allLegislaturas=[], allPartidos={}, allPartidosSigla={};
let mandatosByLeg=[], selectedLeg="", search="";
let onlyActive=true, onlyTitular=false, selectedPartidos=new Set();
let currentProfile=null, activeTab="inicio";
let tipoNomes={};       // materia: sigla → nome completo
let tipoNomesRev={};    // materia: nome completo → sigla
let normaTipoNomes={};  // norma: sigla → nome completo
let normaTipoNomesRev={}; // norma: nome completo → sigla

let tabDataCache = {};

// ── LocalStorage cache (TTL 24h) ──
const STORAGE_TTL = 86_400_000;
const CACHE_VER   = APP_CONFIG.cacheVer || 'v5';
const STORAGE_KEY = `kc_${APP_CONFIG.source}_${CACHE_VER}`;

function storageSave(data) {
  try { localStorage.setItem(STORAGE_KEY, JSON.stringify({ts: Date.now(), data})); } catch(e) {}
}
function storageLoad() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if(!raw) return null;
    const {ts, data} = JSON.parse(raw);
    if(Date.now() - ts > STORAGE_TTL) { localStorage.removeItem(STORAGE_KEY); return null; }
    if(!data?.parlamentares?.length) { localStorage.removeItem(STORAGE_KEY); return null; }
    // Invalida cache sem campo titular (dados pré-fix)
    if(data.parlamentares[0] && !('titular' in data.parlamentares[0])) { localStorage.removeItem(STORAGE_KEY); return null; }
    return data;
  } catch(e) { localStorage.removeItem(STORAGE_KEY); return null; }
}
// ── SessionStorage cache para dados de abas (TTL 30 min, sobrevive F5) ──
const SESSION_TAB_TTL = 1_800_000;
const SESSION_TAB_PFX = 'kc_tab_';

function sessionTabSave(parlId, tabId, data) {
  try { sessionStorage.setItem(SESSION_TAB_PFX+parlId+'_'+tabId, JSON.stringify({ts:Date.now(),data})); } catch(e) {}
}
function sessionTabLoad(parlId, tabId) {
  try {
    const raw = sessionStorage.getItem(SESSION_TAB_PFX+parlId+'_'+tabId);
    if(!raw) return null;
    const {ts,data} = JSON.parse(raw);
    if(Date.now()-ts > SESSION_TAB_TTL) { sessionStorage.removeItem(SESSION_TAB_PFX+parlId+'_'+tabId); return null; }
    return data;
  } catch(e) { return null; }
}
function sessionTabClear() {
  Object.keys(sessionStorage).filter(k=>k.startsWith(SESSION_TAB_PFX)).forEach(k=>sessionStorage.removeItem(k));
}

function clearCache() {
  Object.keys(localStorage).filter(k=>k.startsWith('kc_')).forEach(k=>localStorage.removeItem(k));
  sessionTabClear();
  tabDataCache={};
  location.reload();
}

function atualizarDados() {
  const btn = document.getElementById('btn-atualizar');
  if(btn){ btn.disabled=true; btn.innerHTML='<i class="ph ph-circle-notch" style="animation:spin 1s linear infinite"></i> Atualizando...'; }
  Object.keys(localStorage).filter(k=>k.startsWith('kc_')).forEach(k=>localStorage.removeItem(k));
  sessionTabClear();
  tabDataCache={};
  location.reload();
}

// ── API ──
function proxyUrl(path, params={}) {
  const src = APP_CONFIG.source || 'cmjp';
  let url = PROXY_BASE + '&path=' + encodeURIComponent(path) + '&source=' + encodeURIComponent(src);
  for(const[k,v] of Object.entries(params)) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
  return url;
}

async function fetchWithRetry(url, retries=2) {
  for(let i=0; i<=retries; i++){
    try{
      const res = await fetch(url);
      if(!res.ok){
        if(i<retries){await new Promise(r=>setTimeout(r,1000));continue}
        throw new Error(`HTTP ${res.status}`);
      }
      const text = await res.text();
      if(!text||!text.trim()){
        if(i<retries){await new Promise(r=>setTimeout(r,1000));continue}
        throw new Error('Resposta vazia do servidor');
      }
      return JSON.parse(text);
    }catch(e){
      if(i>=retries) throw e;
      await new Promise(r=>setTimeout(r,1000));
    }
  }
}

async function fetchAllPages(basePath, progressCb, maxPages=400, firstPageData=null, onBatch=null) {
  let firstData = firstPageData;
  if(!firstData){
    try { firstData = await fetchWithRetry(proxyUrl(basePath,{page:1})); }
    catch(e){ console.warn('[App] Erro inicial:', e.message); return []; }
  }

  if(firstData?.__rate_limited) throw new Error('Servidor legislativo está limitando requisições. Aguarde alguns minutos e tente novamente.');

  if(!firstData?.results){
    if(Array.isArray(firstData)) return firstData;
    return [];
  }

  let items = [...firstData.results];
  const totalPages   = firstData.pagination?.total_pages   || 1;
  const totalEntries = firstData.pagination?.total_entries || items.length;

  if(progressCb) progressCb(items.length, totalEntries);
  if(totalPages<=1 || !firstData.pagination?.links?.next) return items;

  const BATCH = 6;
  let pg = 2;
  const cap = Math.min(totalPages, maxPages);
  while(pg<=cap){
    const batch=[];
    for(let i=0; i<BATCH && pg<=cap; i++, pg++){
      batch.push(
        fetchWithRetry(proxyUrl(basePath,{page:pg}))
          .then(d=>d?.results||[])
          .catch(()=>[])
      );
    }
    const results = await Promise.all(batch);
    for(const r of results) items=items.concat(r);
    if(progressCb) progressCb(items.length, totalEntries);
    // onBatch só é chamado após awaits reais (páginas 2+), garantindo que
    // switchTab já aplicou o HTML inicial antes de qualquer atualização
    if(onBatch) onBatch([...items], Math.min(pg-1, totalPages), totalPages);
  }
  return items;
}

function getCached(parlId, tabId, fetchFn) {
  if(!tabDataCache[parlId]) tabDataCache[parlId]={};
  if(!tabDataCache[parlId][tabId]) {
    const stored = sessionTabLoad(parlId, tabId);
    if(stored !== null) {
      tabDataCache[parlId][tabId] = Promise.resolve(stored);
      return tabDataCache[parlId][tabId];
    }
    const p = fetchFn().then(data => { sessionTabSave(parlId, tabId, data); return data; });
    tabDataCache[parlId][tabId] = p.catch(e => { delete tabDataCache[parlId]?.[tabId]; throw e; });
  }
  return tabDataCache[parlId][tabId];
}

function getAutorData(p) {
  return getCached(p.id, 'autor', async () => {
    if(APP_CONFIG.source === 'camara_federal' || APP_CONFIG.source === 'senado') return { id: p.id };
    const nome = p.nome_parlamentar || p.nome_completo || "";
    try {
      const d = await fetchWithRetry(proxyUrl('/base/autor/', {nome}));
      if(d?.results?.length > 0) return d.results[0];
      // Tenta pelo nome completo se diferente
      if(p.nome_completo && p.nome_completo !== nome) {
        const d2 = await fetchWithRetry(proxyUrl('/base/autor/', {nome: p.nome_completo}));
        if(d2?.results?.length > 0) return d2.results[0];
      }
    } catch(e) { console.warn('[getAutorData] erro:', e.message); }
    return null;
  });
}

function stripAutoria(s) { return (s||'').replace(/^Autoria:\s*/i,''); }

// ── Extrai tipo de uma string de matéria/norma ──
function extractTipo(str) {
  const cleaned = stripAutoria(str||'');
  const m = cleaned.match(/^([A-Za-záàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇ][A-Za-záàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇ\s\.]*?)\s+(?:n[ºo°]|nº|\d)/i);
  return m ? m[1].trim() : 'Outros';
}

// ── Extrai ano de uma string ──
function extractYear(str) {
  const matches = [...(str||'').matchAll(/\b((?:19|20)\d{2})\b/g)].map(x=>x[1]);
  return matches.length ? matches[matches.length-1] : null;
}

// ── Formata biografia preservando parágrafos ──
function formatBio(html) {
  if(!html) return '';
  try {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    doc.querySelectorAll('p,div').forEach(el => el.after(document.createTextNode('\n\n')));
    doc.querySelectorAll('br').forEach(br => br.replaceWith(document.createTextNode('\n')));
    doc.querySelectorAll('li').forEach(el => el.after(document.createTextNode('\n')));
    return (doc.body.textContent || '').replace(/\n{3,}/g, '\n\n').trim();
  } catch(e) {
    return html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
  }
}

function renderBioHtml(text) {
  if(!text) return '';
  const paras = text.split(/\n\n+/).filter(p=>p.trim());
  return paras.map(p=>'<p>'+esc(p.trim()).replace(/\n/g,'<br>')+'</p>').join('');
}

function buildCardGrid(list) {
  if(!list.length) {
    if(!allParlamentares.length) return `<div class="empty"><i class="ph ph-wifi-x" style="font-size:40px;color:var(--muted);opacity:.5;display:block;margin-bottom:14px"></i><p style="font-size:15px;font-weight:600;color:var(--text);margin-bottom:6px">Não foi possível carregar os parlamentares</p><p style="font-size:13px;color:var(--muted);margin-bottom:18px">A API pode estar indisponível. Verifique sua conexão e tente novamente.</p><button onclick="clearCache()" style="padding:9px 20px;border-radius:9px;border:none;background:var(--accent);color:#fff;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer"><i class="ph ph-arrows-clockwise"></i> Tentar novamente</button></div>`;
    return `<div class="empty"><div style="font-size:40px;margin-bottom:12px;opacity:.4"><i class="ph ph-magnifying-glass" style="font-size:48px;color:var(--muted)"></i></div><p style="font-size:15px;font-weight:600;color:var(--text)">Nenhum parlamentar encontrado</p><p style="font-size:13px;margin-top:6px;color:var(--muted)">Tente desativar "Apenas Ativos" ou mudar a legislatura.</p></div>`;
  }
  let h='<div class="grid">';
  list.forEach(p=>{
    const n=esc(p.nome_parlamentar||p.nome_completo||"?"),s=imgSrc(p),at=!!p.ativo,ini=initials(p.nome_parlamentar||p.nome_completo);
    const partido=esc(p.partido?.sigla||p.partido_sigla||''),uf=esc(p.uf||'');
    const pc=partyColor(partido);
    h+=`<div class="card" onclick="openProfile(${p.id})" onmouseenter="prefetchCard(${p.id})">`;
    h+=s?`<img class="card-img" src="${esc(s)}" alt="${n}" loading="lazy" onerror="this.outerHTML='<div class=card-avatar>${ini}</div>'">`:`<div class="card-avatar">${ini}</div>`;
    h+=`<div class="card-body"><div class="card-name">${n}<span class="dot ${at?'on':'off'}"></span></div>`;
    if(partido||uf)h+=`<div class="card-meta">${partido?`<span class="card-party" style="background:${pc[0]};color:${pc[1]}">${partido}</span>`:''}${uf?`<span class="card-uf">${uf}</span>`:''}</div>`;
    if(p.nome_completo&&p.nome_completo!==p.nome_parlamentar)h+=`<div class="card-fullname">${esc(p.nome_completo)}</div>`;
    h+='</div></div>';
  });
  return h+'</div>';
}

// ── Helpers ──
function partyColor(p){const m={PT:["#FEE2E2","#991B1B"],PV:["#D1FAE5","#065F46"],PSD:["#DBEAFE","#1E3A8A"],MDB:["#FEF3C7","#92400E"],PSDB:["#DBEAFE","#1D4ED8"],PP:["#E0E7FF","#3730A3"],PL:["#DBEAFE","#1E40AF"],REPUBLICANOS:["#E0E7FF","#312E81"],PDT:["#FEE2E2","#7F1D1D"],PSOL:["#FEF9C3","#713F12"],AVANTE:["#FFEDD5","#9A3412"],SOLIDARIEDADE:["#FFEDD5","#C2410C"],PODEMOS:["#E0F2FE","#0C4A6E"],PSB:["#FEF3C7","#B45309"],CIDADANIA:["#F0FDF4","#166534"],PCDOB:["#FEE2E2","#B91C1C"],REDE:["#D1FAE5","#047857"],PRD:["#E0E7FF","#4338CA"],"UNIÃO BRASIL":["#F3E8FF","#6B21A8"],UNIÃO:["#F3E8FF","#6B21A8"]};return m[(p||"").toUpperCase()]||["#F3F4F6","#374151"]}
function initials(n){return(n||"?").split(" ").filter(Boolean).slice(0,2).map(w=>w[0]).join("").toUpperCase()}
function imgSrc(p){
  if(!p.fotografia) return null;
  const src=APP_CONFIG.source||'cmjp';
  let path=p.fotografia;
  if(path.startsWith('http')) return path; // URL externa: browser carrega direto
  return APP_CONFIG.basePath+'/api/img?source='+encodeURIComponent(src)+'&path='+encodeURIComponent(path);
}
function esc(s){const d=document.createElement("div");d.textContent=s||"";return d.innerHTML}
function fmtDate(d){if(!d)return"—";const s=String(d).split('T')[0];const p=s.split("-");return p.length===3?p[2]+"/"+p[1]+"/"+p[0]:d}
function stripHtml(s){if(!s)return"";return s.replace(/<[^>]*>/g," ").replace(/&[a-z]+;/gi,c=>{const m={"&amp;":"&","&lt;":"<","&gt;":">","&quot;":'"',"&apos;":"'","&atilde;":"ã","&otilde;":"õ","&eacute;":"é","&iacute;":"í","&oacute;":"ó","&uacute;":"ú","&acirc;":"â","&ecirc;":"ê","&ccedil;":"ç","&Aacute;":"Á","&Eacute;":"É","&Iacute;":"Í","&Oacute;":"Ó","&Uacute;":"Ú","&Atilde;":"Ã","&Otilde;":"Õ","&Ccedil;":"Ç","&nbsp;":" "};return m[c.toLowerCase()]||c}).replace(/\s+/g," ").trim()}

// Pré-carrega partido e autor dos primeiros cards em background após a lista renderizar
function _bgPrefetch(list) {
  const targets=list.slice(0,12);
  const idle=window.requestIdleCallback||(fn=>setTimeout(fn,600));
  idle(()=>{
    targets.forEach((p,i)=>setTimeout(()=>{
      getCurrentParty(p.id).catch(()=>{});
      getAutorData(p).catch(()=>{});
    },i*180));
  });
}

// Pré-carrega dados ao passar o mouse no card (warm-up do cache antes do clique)
function prefetchCard(id) {
  const p=allParlamentares.find(x=>x.id===id);
  if(!p) return;
  getCurrentParty(p.id).catch(()=>{});
  getAutorData(p).catch(()=>{});
}

async function getCurrentParty(parlId) {
  return getCached(parlId, '_partido', async () => {
    try {
      const d = await fetchWithRetry(proxyUrl(`/parlamentares/filiacao/?parlamentar=${parlId}&o=-data`, {page:1}));
      const active = (d?.results||[]).find(f=>!f.data_desfiliacao);
      if(!active) return null;
      return typeof active.partido==='string' ? active.partido : (allPartidosSigla[active.partido]||null);
    } catch(e) { return null; }
  });
}

// ── Pagination helper ──
function paginateTable(items, pageSize, currentPage, renderRowFn, tableHeadHtml, paginationId) {
  const totalPages = Math.ceil(items.length / pageSize);
  const pg = Math.max(1, Math.min(currentPage, totalPages));
  const start = (pg-1)*pageSize;
  const pageItems = items.slice(start, start+pageSize);

  let h = `<div class="table-wrap"><table><thead>${tableHeadHtml}</thead><tbody>`;
  pageItems.forEach((item, i) => { h += renderRowFn(item, start + i); });
  h += '</tbody></table></div>';

  if(totalPages>1){
    h += `<div class="pagination" id="${paginationId}">`;
    h += `<button class="pg-btn${pg<=1?' disabled':''}" onclick="goPage('${paginationId}',${pg-1})">← Anterior</button>`;
    for(let i=1; i<=totalPages; i++){
      if(i===1||i===totalPages||(i>=pg-2&&i<=pg+2)){
        h+=`<button class="pg-btn${i===pg?' active':''}" onclick="goPage('${paginationId}',${i})">${i}</button>`;
      }else if(i===pg-3||i===pg+3){
        h+='<span class="pg-dots">...</span>';
      }
    }
    h+=`<button class="pg-btn${pg>=totalPages?' disabled':''}" onclick="goPage('${paginationId}',${pg+1})">Próxima →</button>`;
    h+='</div>';
  }
  return h;
}

let tablePages = {};
function goPage(paginationId, pg) {
  tablePages[paginationId] = pg;
  switchTab(activeTab);
}

// ── Accordion de tipos ──
function toggleGrupo(id) {
  const el = document.getElementById(id);
  const caret = document.getElementById('caret_'+id);
  if(!el) return;
  const open = el.style.display === 'none';
  el.style.display = open ? 'block' : 'none';
  if(caret) caret.style.transform = open ? 'rotate(180deg)' : '';
}

// ══════════════════════════════════════════════════════
// LIST VIEW
// ══════════════════════════════════════════════════════
function getFilteredList() {
  let list;
  if(APP_CONFIG.source==='senado'){
    // Senado: titular vem do campo p.titular (do bulk/API), não dos mandatos
    // A API /lista/legislatura retorna todos como titular:true — inútil para filtrar
    list=allParlamentares.map(p=>({...p,_tit:p.titular!==false}));
  } else if(selectedLeg&&mandatosByLeg.length>0){
    const pids=new Set(mandatosByLeg.map(m=>m.parlamentar));
    const tids=new Set(mandatosByLeg.filter(m=>m.titular).map(m=>m.parlamentar));
    list=allParlamentares.filter(p=>pids.has(p.id)).map(p=>({...p,_tit:tids.has(p.id)}));
  }else{
    list=allParlamentares.map(p=>({...p,_tit:p.titular!==false}));
  }
  if(onlyTitular)list=list.filter(p=>!!p._tit);
  if(onlyActive)list=list.filter(p=>!!p.ativo);
  if(selectedPartidos.size>0){
    list=list.filter(p=>selectedPartidos.has(p.partido?.sigla||p.partido_sigla||''));
  }
  if(search.trim()){
    const s=search.toLowerCase();
    list=list.filter(p=>{
      const partido=(p.partido?.sigla||p.partido_sigla||'').toLowerCase();
      const uf=(p.uf||'').toLowerCase();
      return (p.nome_parlamentar||"").toLowerCase().includes(s)
        || (p.nome_completo||"").toLowerCase().includes(s)
        || partido.includes(s)
        || uf.includes(s);
    });
  }
  list.sort((a,b)=>(a.nome_parlamentar||"").localeCompare(b.nome_parlamentar||""));
  return list;
}

function buildLegInfo() {
  const cl      = allLegislaturas.find(l=>String(l.id)===selectedLeg);
  if(!cl) return '';
  const isAtual = allLegislaturas[0] && String(allLegislaturas[0].id)===selectedLeg;
  const badge   = isAtual ? ' <span style="font-size:.72rem;font-weight:700;background:#16a34a;color:#fff;padding:1px 7px;border-radius:99px;vertical-align:middle">atual</span>' : '';
  return ` na ${cl.numero}ª Legislatura (${new Date(cl.data_inicio).getFullYear()}–${new Date(cl.data_fim).getFullYear()})${badge}`;
}

function renderGrid() {
  const list = getFilteredList();
  const li   = buildLegInfo();
  const statsEl = document.getElementById("listStats");
  const gridEl  = document.getElementById("listGrid");
  if(!statsEl||!gridEl) return renderList();
  statsEl.innerHTML=`<span class="stats-badge">${list.length}</span> parlamentar${list.length!==1?'es':''} encontrado${list.length!==1?'s':''}${li}`;
  gridEl.innerHTML=buildCardGrid(list);
}

function renderList() {
  const main=document.getElementById("mainContent");
  const list=getFilteredList();
  const li  =buildLegInfo();

  const partidos=[...new Set(allParlamentares.map(p=>p.partido?.sigla||p.partido_sigla||'').filter(Boolean))].sort();
  const nSel=selectedPartidos.size;
  const btnLabel=nSel===0?'Todos os partidos':nSel===1?[...selectedPartidos][0]:`${nSel} partidos`;
  let h='<div class="controls"><div class="search-wrap"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>';
  h+=`<input type="text" id="searchInput" placeholder="Pesquisar parlamentar..." value="${esc(search)}" oninput="onSearch(this.value)"></div>`;
  h+=`<div class="global-combo${nSel>0?' open':''}" id="partidoCombo" onclick="event.stopPropagation()">`;
  h+=`<button type="button" class="global-combo-btn" onclick="togglePartidoDropdown()"${nSel>0?' style="border-color:var(--accent);font-weight:600"':''}>`;
  h+=`<span class="global-combo-label">${esc(btnLabel)}</span>`;
  h+=`<i class="ph ph-caret-down global-combo-caret"></i></button>`;
  h+=`<div class="global-combo-menu" style="min-width:200px">`;
  h+=`<div class="global-combo-search"><input type="text" placeholder="Buscar partido..." oninput="filterPartidoOptions(this.value)" onclick="event.stopPropagation()" autocomplete="off"></div>`;
  h+=`<div class="global-combo-list" id="partidoList">`;
  if(nSel>0) h+=`<button type="button" class="global-combo-item" data-search="" onclick="clearPartidos()" style="color:#dc2626;font-weight:600"><span class="global-combo-item-main">Limpar seleção</span></button>`;
  partidos.forEach(s=>{
    const chk=selectedPartidos.has(s);
    h+=`<button type="button" class="global-combo-item${chk?' active':''}" data-search="${s.toLowerCase()}" onclick="togglePartidoItem('${esc(s)}')">`;
    h+=`<span style="display:inline-flex;align-items:center;gap:8px"><span style="width:14px;height:14px;border-radius:3px;border:1.5px solid ${chk?'var(--accent)':'var(--border)'};background:${chk?'var(--accent)':'#fff'};flex-shrink:0;display:inline-flex;align-items:center;justify-content:center">${chk?'<svg width="9" height="9" viewBox="0 0 10 10"><polyline points="1.5,5 4,8 8.5,2" stroke="#fff" stroke-width="1.8" fill="none" stroke-linecap="round"/></svg>':''}</span>${esc(s)}</span>`;
    h+=`</button>`;
  });
  h+=`</div></div></div>`;
  h+=`</div>`;
  h+=`<div class="stats" id="listStats"><span class="stats-badge">${list.length}</span> parlamentar${list.length!==1?'es':''} encontrado${list.length!==1?'s':''}${li}</div>`;
  h+='<div id="listGrid"></div>';
  main.innerHTML=h;

  document.getElementById("listGrid").innerHTML=buildCardGrid(list);
  _bgPrefetch(list);
}

// ══════════════════════════════════════════════════════
// PROFILE WITH TABS
// ══════════════════════════════════════════════════════
const TABS=[
  {id:'inicio',    label:'Início',               icon:'<i class="ph ph-house"></i>'},
  {id:'mandatos',  label:'Mandatos',             icon:'<i class="ph ph-calendar"></i>'},
  {id:'materias',  label:'Matérias',             icon:'<i class="ph ph-file-text"></i>',    labelFor:{'camara_federal':'Proposições'}},
  {id:'normas',    label:'Normas',               icon:'<i class="ph ph-scroll"></i>'},
  {id:'emendas',   label:'Emendas',              icon:'<i class="ph ph-money"></i>',         show:['camara_federal']},
  {id:'filiacoes', label:'Filiações Partidárias',icon:'<i class="ph ph-flag"></i>'},
  {id:'comissoes', label:'Comissões',            icon:'<i class="ph ph-users"></i>'},
  {id:'relatorias',label:'Relatorias',           icon:'<i class="ph ph-clipboard-text"></i>'},
  {id:'frentes',   label:'Frentes',              icon:'<i class="ph ph-handshake"></i>'},
  {id:'dashboard', label:'Dashboard',            icon:'<i class="ph ph-chart-bar"></i>'},
  {id:'agente',    label:'Sentinela',             icon:'<i class="ph ph-robot"></i>'},
];
function getVisibleTabs() {
  const src = APP_CONFIG.source||'';
  return TABS
    .filter(t=>!t.hide||!t.hide.includes(src))
    .filter(t=>!t.show||t.show.includes(src))
    .map(t=>({...t, label:(t.labelFor&&t.labelFor[src])||t.label}));
}

async function renderProfileShell(p) {
  const n=esc(p.nome_parlamentar||p.nome_completo||"?"),nc=esc(p.nome_completo||"");
  const s=imgSrc(p),at=!!p.ativo,ini=initials(p.nome_parlamentar||p.nome_completo);

  let h='<div style="padding-top:8px;padding-bottom:40px">';
  h+='<button class="profile-back" onclick="backToList()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Voltar</button>';
  h+='<div class="profile-hero">';
  h+=s?`<img class="profile-img" src="${esc(s)}" alt="${n}" onerror="this.outerHTML='<div class=profile-avatar>${ini}</div>'">`:`<div class="profile-avatar">${ini}</div>`;
  h+='<div class="profile-info">';
  h+=`<h1>${n}</h1>`;
  h+='<div class="profile-details">';
  if(nc&&nc!==n) h+=`<div class="detail-row"><span class="detail-label">Nome Completo:</span><span class="detail-value">${nc}</span></div>`;
  h+=`<div class="detail-row" id="party-row" style="display:none"><span class="detail-label">Partido:</span><span class="detail-value" id="party-slot"></span></div>`;
  if(p.telefone) h+=`<div class="detail-row"><span class="detail-label">Telefone:</span><span class="detail-value">${esc(p.telefone)}</span></div>`;
  if(p.telefone_celular) h+=`<div class="detail-row"><span class="detail-label">Celular:</span><span class="detail-value">${esc(p.telefone_celular)}</span></div>`;
  if(p.email) h+=`<div class="detail-row"><span class="detail-label">E-mail:</span><span class="detail-value"><a href="mailto:${esc(p.email)}" style="color:var(--accent)">${esc(p.email)}</a></span></div>`;
  if(p.endereco_web||p.homepage) h+=`<div class="detail-row"><span class="detail-label">Homepage:</span><span class="detail-value"><a href="${esc(p.endereco_web||p.homepage)}" target="_blank" style="color:var(--accent)">${esc(p.endereco_web||p.homepage)}</a></span></div>`;
  if(p.numero_gab_parlamentar||p.gabinete) h+=`<div class="detail-row"><span class="detail-label">Gabinete:</span><span class="detail-value">${esc(p.numero_gab_parlamentar||p.gabinete)}</span></div>`;
  if(p.profissao) h+=`<div class="detail-row"><span class="detail-label">Profissão:</span><span class="detail-value">${esc(p.profissao)}</span></div>`;
  if(p.escolaridade) h+=`<div class="detail-row"><span class="detail-label">Escolaridade:</span><span class="detail-value">${esc(p.escolaridade)}</span></div>`;
  if(p.data_nascimento){const nasc=[p.municipio_nascimento,p.uf_nascimento].filter(Boolean).join(' - '); h+=`<div class="detail-row"><span class="detail-label">Nascimento:</span><span class="detail-value">${fmtDate(p.data_nascimento)}${nasc?' · '+esc(nasc):''}</span></div>`;}
  h+=`<div class="detail-row"><span class="detail-label">Situação:</span><span class="detail-value"><span class="tag" style="background:${at?'var(--accent-light)':'var(--red-light)'};color:${at?'var(--accent)':'var(--red)'}"><span class="dot ${at?'on':'off'}"></span> ${at?'Ativo':'Inativo'}</span></span></div>`;
  h+='</div></div></div>';
  h+='<div class="tabs-nav">';
  getVisibleTabs().forEach(t=>{h+=`<button class="tab-btn${activeTab===t.id?' active':''}" onclick="switchTab('${t.id}')">${t.label}</button>`});
  h+='</div>';
  h+='<div id="tabContent"><div class="tab-loader"><div class="spinner"></div></div></div>';
  h+='</div>';
  return h;
}

async function switchTab(tabId) {
  activeTab=tabId;
  const vt=getVisibleTabs();
  document.querySelectorAll('.tab-btn').forEach((btn,i)=>{btn.classList.toggle('active',vt[i]?.id===tabId)});
  const el=document.getElementById('tabContent');
  el.innerHTML='<div class="tab-loader"><div class="spinner"></div></div>';
  const p=currentProfile;
  try{
    let html='';
    if     (tabId==='dashboard') html=await renderTabDashboard(p);
    else if(tabId==='inicio')    html=await renderTabInicio(p);
    else if(tabId==='mandatos')  html=await renderTabMandatos(p);
    else if(tabId==='materias')  html=await renderTabMaterias(p);
    else if(tabId==='normas')    html=await renderTabNormas(p);
    else if(tabId==='emendas')   html=await renderTabEmendas(p);
    else if(tabId==='filiacoes') html=await renderTabFiliacoes(p);
    else if(tabId==='comissoes') html=await renderTabComissoes(p);
    else if(tabId==='relatorias')html=await renderTabRelatorias(p);
    else if(tabId==='frentes')   html=await renderTabFrentes(p);
    else if(tabId==='agente')    html=await renderTabAgente(p);
    el.innerHTML=html;
    if(tabId==='dashboard') setTimeout(initDashboardCharts,0);
    if(tabId==='agente')    setTimeout(initAgenteEvents,0);
    const _extrasAbas=['inicio','materias','normas','emendas','filiacoes','comissoes','relatorias','frentes'];
    if(_extrasAbas.includes(tabId) && p) {
      fetchExtras(p.id, tabId).then(extras => {
        if(extras.length) injectExtras(el, tabId, extras);
      });
    }
  }catch(e){
    console.error('[switchTab]', tabId, e);
    el.innerHTML=`<div class="empty-tab">
      <i class="ph ph-warning-circle" style="font-size:32px;color:var(--red);margin-bottom:10px;display:block"></i>
      <p style="color:var(--red);font-weight:600;margin-bottom:6px">Erro ao carregar aba</p>
      <p style="font-size:12px;color:var(--muted);margin-bottom:14px">${esc(e.message)}</p>
      <button onclick="switchTab('${tabId}')" style="padding:7px 16px;border:1.5px solid var(--accent);border-radius:8px;background:var(--accent-light);color:var(--accent);font-size:13px;font-weight:600;font-family:inherit;cursor:pointer">
        <i class="ph ph-arrows-clockwise"></i> Tentar novamente
      </button>
    </div>`;
  }
}

// ══════════════════════════════════════════════════════
// TAB: DASHBOARD
// ══════════════════════════════════════════════════════
let dashboardAllMaterias    = null;
let dashboardAllNormas      = null;
let dashboardAllEmendas     = null;
let dashboardChartInstances = {};
let _dashEmendaAno          = new Date().getFullYear();
let _dashEmendaFuncao       = '';

async function renderTabDashboard(p) {
  const autorData = await getAutorData(p);
  if(!autorData) return `<div class="empty-tab"><i class="ph ph-user-x" style="font-size:28px;color:var(--muted);display:block;margin-bottom:10px"></i><p style="font-weight:600;color:var(--text);margin-bottom:4px">Autor não localizado na API</p><p style="font-size:12px;color:var(--muted)">O parlamentar não foi encontrado no sistema de autoria do SAPL. Isso pode ocorrer quando o nome cadastrado difere da base legislativa.</p></div>`;

  const isCamaraDash = APP_CONFIG.source==='camara_federal';
  const hasNormas = true; // camara_federal: proposições com codSituacao=1140; SAPL: normas jurídicas

  // Carrega tipos de matéria e norma para resolução de siglas
  const [allMaterias, allNormasData, , , allEmendasRaw] = await Promise.all([
    getCached(p.id,'all_materias',()=>fetchAllPages(`/materia/autoria/?autor=${autorData.id}&o=-id`)),
    hasNormas ? getCached(p.id,'normas',()=>fetchAllPages(`/norma/autorianorma/?autor=${autorData.id}`)) : Promise.resolve([]),
    !Object.keys(tipoNomes).length
      ? getCached('global','tipomateria',()=>fetchAllPages('/materia/tipomateria/')).then(ts=>{ ts.forEach(t=>{ if(t.sigla){ const s=t.sigla.toUpperCase(),d=(t.descricao||t.sigla).toUpperCase(); tipoNomes[s]=d; tipoNomesRev[d]=s; } }); }).catch(()=>{})
      : Promise.resolve(),
    hasNormas && !Object.keys(normaTipoNomes).length
      ? getCached('global','tiponorma',()=>fetchAllPages('/norma/tiponormajuridica/')).then(ts=>{ ts.forEach(t=>{ if(t.sigla){ const s=t.sigla.toUpperCase(),d=(t.descricao||t.sigla).toUpperCase(); normaTipoNomes[s]=d; normaTipoNomesRev[d]=s; } }); }).catch(()=>{})
      : Promise.resolve(),
    isCamaraDash
      ? getCached(p.id,`emendas_${_dashEmendaAno}`,()=>fetchAllPages(`/emendas/parlamentar/?parlamentar=${p.id}&ano=${_dashEmendaAno}`)).catch(()=>[])
      : Promise.resolve([]),
  ]);

  dashboardAllMaterias = allMaterias;
  dashboardAllNormas   = allNormasData;
  dashboardAllEmendas  = allEmendasRaw || [];

  const totalMaterias  = allMaterias.length;
  const totalNormas    = allNormasData.length;
  const totalPrimeiro  = allMaterias.filter(m=>m.primeiro_autor).length;
  const totalCoautoria = totalMaterias - totalPrimeiro;

  // Anos disponíveis
  const allYears = new Set();
  allMaterias.forEach(m=>{ const y=extractMateriaInfo(m).ano; if(y&&y!=='—') allYears.add(y); });
  if(hasNormas) allNormasData.forEach(n=>{ const y=extractNormaInfo(n).ano; if(y&&y!=='—') allYears.add(y); });
  const yearsArr = [...allYears].sort();

  // Tipos únicos com sigla para exibição no select
  const matTiposMap  = {};
  allMaterias.forEach(m=>{ const {tipoRaw,sigla}=extractMateriaInfo(m); if(!matTiposMap[tipoRaw]) matTiposMap[tipoRaw]=sigla; });
  const normTiposMap = {};
  if(hasNormas) allNormasData.forEach(n=>{ const {tipoRaw,nome}=extractNormaInfo(n); if(!normTiposMap[tipoRaw]) normTiposMap[tipoRaw]=nome; });

  const matTiposRaw  = Object.keys(matTiposMap).sort();
  const normTiposRaw = Object.keys(normTiposMap).sort();

  let h='<div class="dashboard-grid">';

  // KPIs
  h+='<div class="kpi-row">';
  h+=`<div class="kpi-card"><div class="kpi-value">${totalMaterias.toLocaleString('pt-BR')}</div><div class="kpi-label">${isCamaraDash?'Proposições':'Matérias'}</div></div>`;
  if(hasNormas&&totalNormas>0) h+=`<div class="kpi-card"><div class="kpi-value">${totalNormas.toLocaleString('pt-BR')}</div><div class="kpi-label">${isCamaraDash?'Sancionadas':'Normas'}</div></div>`;
  h+=`<div class="kpi-card"><div class="kpi-value">${totalPrimeiro.toLocaleString('pt-BR')}</div><div class="kpi-label">1º Autor</div></div>`;
  h+=`<div class="kpi-card"><div class="kpi-value">${totalCoautoria.toLocaleString('pt-BR')}</div><div class="kpi-label">Co-participação</div></div>`;
  h+='</div>';

  // Filtros
  h+='<div class="dash-filters">';
  h+='<span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;flex-shrink:0">Filtrar:</span>';
  if(yearsArr.length>1){
    h+='<select id="filterAno" onchange="applyDashFilters()" class="dash-filter-select"><option value="">Todos os anos</option>';
    yearsArr.forEach(y=>{h+=`<option value="${y}">${y}</option>`});
    h+='</select>';
  }
  const truncOpt=s=>s.length>55?s.slice(0,52)+'…':s;
  if(matTiposRaw.length>1){
    h+=`<select id="filterTipoMateria" onchange="applyDashFilters()" class="dash-filter-select"><option value="">${isCamaraDash?'Tipo de proposição':'Tipo de matéria'}</option>`;
    matTiposRaw.forEach(raw=>{const sigla=matTiposMap[raw]; h+=`<option value="${esc(raw)}" title="${esc(sigla)}">${esc(truncOpt(sigla))}</option>`});
    h+='</select>';
  }
  if(hasNormas && normTiposRaw.length>1){
    h+=`<select id="filterTipoNorma" onchange="applyDashFilters()" class="dash-filter-select"><option value="">${isCamaraDash?'Tipo sancionado':'Tipo de norma'}</option>`;
    normTiposRaw.forEach(raw=>{const nome=normTiposMap[raw]; h+=`<option value="${esc(raw)}" title="${esc(nome)}">${esc(truncOpt(nome))}</option>`});
    h+='</select>';
  }
  h+='</div>';

  // Gráficos
  h+=`<div class="chart-row" style="grid-template-columns:1fr 1fr">`;
  const matLabel = isCamaraDash ? 'Proposições' : 'Matérias';
  const normLabel = isCamaraDash ? 'Sancionadas' : 'Normas';
  h+=`<div class="chart-box"><h3 class="section-title" style="font-size:14px;margin-bottom:12px">${matLabel} por Ano</h3><div style="position:relative;height:200px"><canvas id="chartProdAnual"></canvas></div></div>`;
  h+=`<div class="chart-box"><h3 class="section-title" style="font-size:14px;margin-bottom:12px">${matLabel} por Tipo</h3><div style="position:relative;height:200px"><canvas id="chartMateriasTipo"></canvas></div></div>`;
  if(hasNormas){
    h+=`<div class="chart-box"><h3 class="section-title" style="font-size:14px;margin-bottom:12px">${normLabel} por Ano</h3><div style="position:relative;height:200px"><canvas id="chartNormasAnual"></canvas></div></div>`;
    h+=`<div class="chart-box"><h3 class="section-title" style="font-size:14px;margin-bottom:12px">${normLabel} por Tipo</h3><div style="position:relative;height:200px"><canvas id="chartNormasTipo"></canvas></div></div>`;
  }
  h+='</div>';

  // ── Seção Emendas (só Câmara Federal, só se tiver dados) ────
  if(isCamaraDash) {
    h+=`<div id="dashEmendaSection">${buildDashEmendaHTML(dashboardAllEmendas, p.id)}</div>`;
  }

  h+='</div>';
  return h;
}

function buildDashEmendaHTML(emendas, parlId) {
  if(!emendas || !emendas.length) return '';

  const anoHoje = new Date().getFullYear();
  const anosE = [];
  for(let y=anoHoje; y>=2015; y--) anosE.push(y);

  const funcoesE = [...new Set(emendas.map(e=>e.funcao||'').filter(Boolean))].sort();
  const listaE = _dashEmendaFuncao ? emendas.filter(e=>e.funcao===_dashEmendaFuncao) : emendas;

  const fmtEm  = v => v>0 ? 'R$ '+v.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}) : '—';
  const totEmp = listaE.reduce((s,e)=>s+(e.valor_empenhado||0),0);
  const totLiq = listaE.reduce((s,e)=>s+(e.valor_liquidado||0),0);
  const totPag = listaE.reduce((s,e)=>s+(e.valor_pago||0),0);

  const selStyleE = 'padding:5px 10px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface);font-size:12px;font-family:inherit;color:var(--text)';

  let h=`<div style="border-top:1px solid var(--border);margin-top:24px;padding-top:20px">`;
  h+=`<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px">`;
  h+=`<h3 class="section-title" style="margin:0;font-size:15px">Emendas Parlamentares ${_dashEmendaAno}</h3>`;
  h+=`<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">`;
  h+=`<select onchange="switchDashEmendaAno(${parlId},this.value)" style="${selStyleE}">`;
  anosE.forEach(y=>{ h+=`<option value="${y}"${y===_dashEmendaAno?' selected':''}>${y}</option>`; });
  h+=`</select>`;
  if(funcoesE.length>1){
    h+=`<select onchange="applyDashEmendaFiltros('funcao',this.value)" style="${selStyleE}">`;
    h+=`<option value="">Todas as funções</option>`;
    funcoesE.forEach(f=>{ h+=`<option value="${esc(f)}"${_dashEmendaFuncao===f?' selected':''}>${esc(f)}</option>`; });
    h+=`</select>`;
  }
  if(_dashEmendaFuncao){
    h+=`<button onclick="applyDashEmendaFiltros('funcao','')" style="padding:5px 10px;border-radius:8px;border:1.5px solid var(--border);background:none;font-size:12px;font-family:inherit;color:var(--muted);cursor:pointer">× Limpar</button>`;
  }
  h+=`</div></div>`;

  h+=`<div class="kpi-row" style="margin-bottom:16px">`;
  h+=`<div class="kpi-card"><div class="kpi-value">${listaE.length}</div><div class="kpi-label">Emendas</div></div>`;
  h+=`<div class="kpi-card"><div class="kpi-value" style="font-size:12px;color:#2563eb">${fmtEm(totEmp)}</div><div class="kpi-label">Empenhado</div></div>`;
  h+=`<div class="kpi-card"><div class="kpi-value" style="font-size:12px;color:#d97706">${fmtEm(totLiq)}</div><div class="kpi-label">Liquidado</div></div>`;
  h+=`<div class="kpi-card"><div class="kpi-value" style="font-size:12px;color:#16a34a">${fmtEm(totPag)}</div><div class="kpi-label">Pago</div></div>`;
  h+=`</div>`;

  h+=`<div class="chart-row" style="grid-template-columns:1fr 1fr">`;
  h+=`<div class="chart-box"><h3 class="section-title" style="font-size:14px;margin-bottom:12px">Empenhado por Função</h3><div style="position:relative;height:260px"><canvas id="chartEmendaFuncao"></canvas></div></div>`;
  h+=`<div class="chart-box"><h3 class="section-title" style="font-size:14px;margin-bottom:12px">Top Localidades (Empenhado)</h3><div style="position:relative;height:260px"><canvas id="chartEmendaLocal"></canvas></div></div>`;
  h+=`</div>`;
  h+=`</div>`;
  return h;
}

function applyDashFilters() {
  const anoSel      = document.getElementById('filterAno')?.value          || '';
  const tipoMatSel  = document.getElementById('filterTipoMateria')?.value  || '';
  const tipoNormSel = document.getElementById('filterTipoNorma')?.value     || '';

  let filtMat  = dashboardAllMaterias||[];
  let filtNorm = dashboardAllNormas||[];

  if(anoSel)      { filtMat  = filtMat.filter(m=>extractMateriaInfo(m).ano===anoSel); filtNorm = filtNorm.filter(n=>extractNormaInfo(n).ano===anoSel); }
  if(tipoMatSel)  { filtMat  = filtMat.filter(m=>extractMateriaInfo(m).tipoRaw===tipoMatSel); }
  if(tipoNormSel) { filtNorm = filtNorm.filter(n=>extractNormaInfo(n).tipoRaw===tipoNormSel); }

  redrawDashCharts(filtMat, filtNorm);
}

// Plugin inline: exibe o valor numérico em cima de cada barra
const barValuePlugin={
  id:'barValue',
  afterDatasetsDraw(chart){
    if(chart.options?.plugins?.barValue === false) return;
    const {ctx}=chart;
    ctx.save();
    chart.data.datasets.forEach((_ds,i)=>{
      chart.getDatasetMeta(i).data.forEach((bar,j)=>{
        const v=chart.data.datasets[i].data[j];
        if(!v) return;
        ctx.fillStyle='#374151';
        ctx.font="600 10px 'Inter',sans-serif";
        ctx.textAlign='center';
        ctx.textBaseline='bottom';
        ctx.fillText(v,bar.x,bar.y-2);
      });
    });
    ctx.restore();
  }
};

// Plugin local para gráficos de emendas: formata valores e ajusta posição para barras horizontais
function makeEmendaLabelPlugin(fmt){
  return {
    id:'emendaValueLabels',
    afterDatasetsDraw(chart){
      const {ctx}=chart;
      const isH=chart.options.indexAxis==='y';
      ctx.save();
      ctx.font='600 12px Arial,sans-serif';
      ctx.fillStyle='#374151';
      chart.data.datasets.forEach((_ds,i)=>{
        chart.getDatasetMeta(i).data.forEach((bar,j)=>{
          const v=chart.data.datasets[i].data[j];
          if(!v) return;
          if(isH){
            ctx.textAlign='left';
            ctx.textBaseline='middle';
            ctx.fillText(fmt(v),bar.x+5,bar.y);
          }else{
            ctx.textAlign='center';
            ctx.textBaseline='bottom';
            ctx.fillText(fmt(v),bar.x,bar.y-3);
          }
        });
      });
      ctx.restore();
    }
  };
}
if(typeof Chart!=='undefined') Chart.register(barValuePlugin);

function redrawDashCharts(materias, normas) {
  Object.values(dashboardChartInstances).forEach(c=>{try{c.destroy()}catch(e){}});
  dashboardChartInstances={};
  // Restaura canvases que showEmpty pode ter ocultado
  ['chartProdAnual','chartMateriasTipo','chartNormasAnual','chartNormasTipo'].forEach(id=>{
    const c=document.getElementById(id);
    if(c) c.style.display='';
    const w=c?.closest('[style*="height:200px"]');
    if(w){const m=w.querySelector('.chart-empty-msg');if(m)m.remove();}
  });

  const COLORS=['#1A6B4F','#C9A84C','#3B82F6','#EC4899','#8B5CF6','#F59E0B','#10B981','#EF4444','#0EA5E9','#A855F7'];
  const font={family:"'Inter',sans-serif",size:11};
  function mountChart(id,config){
    const canvas=document.getElementById(id);
    if(!canvas) return;
    canvas.style.display='';
    const wrap=canvas.closest('[style*="height:200px"]');
    if(wrap){const m=wrap.querySelector('.chart-empty-msg');if(m)m.remove();}
    dashboardChartInstances[id]=new Chart(canvas,config);
  }
  function showEmpty(id){
    const canvas=document.getElementById(id);
    if(!canvas) return;
    canvas.style.display='none';
    const wrap=canvas.closest('[style*="height:200px"]');
    if(!wrap) return;
    if(!wrap.querySelector('.chart-empty-msg')){
      const d=document.createElement('div');
      d.className='chart-empty-msg';
      d.style.cssText='display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);font-size:12px;text-align:center;padding:8px';
      d.innerHTML='Nenhum dado disponível<br>para o filtro selecionado';
      wrap.appendChild(d);
    }
  }

  // helper: agrupa mapa {raw:{sigla,count}} em top N + "Demais" (sem duplicar label)
  function buildTipoChart(byTipo, TOP=8){
    const sorted=Object.keys(byTipo).filter(r=>byTipo[r].count>0).sort((a,b)=>byTipo[b].count-byTipo[a].count);
    if(!sorted.length) return null;
    const top=sorted.slice(0,TOP);
    const restSum=sorted.slice(TOP).reduce((s,r)=>s+byTipo[r].count,0);
    const labels=top.map(r=>byTipo[r].sigla);
    const values=top.map(r=>byTipo[r].count);
    if(restSum>0){
      const restLabel=labels.includes('Outros')?'Demais tipos':'Outros';
      labels.push(restLabel);values.push(restSum);
    }
    return {labels,values};
  }

  // Matérias por Ano (ordem cronológica)
  const matByYear={};
  materias.forEach(m=>{const y=extractMateriaInfo(m).ano;if(y&&y!=='—')matByYear[y]=(matByYear[y]||0)+1});
  const matYears=Object.keys(matByYear).filter(y=>matByYear[y]>0).sort();
  if(matYears.length){
    mountChart('chartProdAnual',{type:'bar',
      data:{labels:matYears,datasets:[{label:'Matérias',data:matYears.map(y=>matByYear[y]),backgroundColor:'#1A6B4F',borderRadius:4}]},
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{font},grid:{color:'rgba(0,0,0,.05)'}},x:{ticks:{font},grid:{display:false}}}}
    });
  } else { showEmpty('chartProdAnual'); }

  // Matérias por Tipo
  const matByTipo={};
  materias.forEach(m=>{const {tipoRaw,sigla}=extractMateriaInfo(m);if(!matByTipo[tipoRaw])matByTipo[tipoRaw]={sigla,count:0};matByTipo[tipoRaw].count++});
  const matTipo=buildTipoChart(matByTipo);
  if(matTipo){
    mountChart('chartMateriasTipo',{type:'bar',
      data:{labels:matTipo.labels,datasets:[{label:'Matérias',data:matTipo.values,backgroundColor:matTipo.labels.map((_,i)=>COLORS[i%COLORS.length]),borderRadius:4}]},
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{font}},x:{ticks:{font,maxRotation:35},grid:{display:false}}}}
    });
  } else { showEmpty('chartMateriasTipo'); }

  // Normas por Ano (ordem cronológica)
  const normByYear={};
  normas.forEach(n=>{const y=extractNormaInfo(n).ano;if(y&&y!=='—')normByYear[y]=(normByYear[y]||0)+1});
  const normYears=Object.keys(normByYear).filter(y=>normByYear[y]>0).sort();
  if(normYears.length){
    mountChart('chartNormasAnual',{type:'bar',
      data:{labels:normYears,datasets:[{label:'Normas',data:normYears.map(y=>normByYear[y]),backgroundColor:'#C9A84C',borderRadius:4}]},
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{font},grid:{color:'rgba(0,0,0,.05)'}},x:{ticks:{font},grid:{display:false}}}}
    });
  } else { showEmpty('chartNormasAnual'); }

  // Normas por Tipo
  const normByTipo={};
  normas.forEach(n=>{const {tipoRaw,sigla}=extractNormaInfo(n);if(!normByTipo[tipoRaw])normByTipo[tipoRaw]={sigla,count:0};normByTipo[tipoRaw].count++});
  const normTipo=buildTipoChart(normByTipo);
  if(normTipo){
    mountChart('chartNormasTipo',{type:'bar',
      data:{labels:normTipo.labels,datasets:[{label:'Normas',data:normTipo.values,backgroundColor:normTipo.labels.map((_,i)=>COLORS[i%COLORS.length]),borderRadius:4}]},
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{font}},x:{ticks:{font,maxRotation:35},grid:{display:false}}}}
    });
  } else { showEmpty('chartNormasTipo'); }
}

function initDashboardCharts() {
  if(typeof Chart==='undefined') return;
  redrawDashCharts(dashboardAllMaterias||[], dashboardAllNormas||[]);
  redrawDashEmendaCharts(dashboardAllEmendas||[]);
}

function applyDashEmendaFiltros(campo, valor) {
  if(campo==='funcao') _dashEmendaFuncao = valor;
  const p = currentProfile;
  if(!p) return;
  const section = document.getElementById('dashEmendaSection');
  if(!section) return;
  section.innerHTML = buildDashEmendaHTML(dashboardAllEmendas||[], p.id);
  setTimeout(() => redrawDashEmendaCharts(dashboardAllEmendas||[]), 0);
}

async function switchDashEmendaAno(parlId, ano) {
  _dashEmendaAno    = parseInt(ano);
  _dashEmendaFuncao = '';
  const ck = `emendas_${_dashEmendaAno}`;
  if(tabDataCache[parlId]) delete tabDataCache[parlId][ck];
  const p = allParlamentares.find(x=>x.id===parlId)||currentProfile;
  if(!p) return;
  const section = document.getElementById('dashEmendaSection');
  if(!section) return;
  section.innerHTML = `<div style="border-top:1px solid var(--border);margin-top:24px;padding-top:28px;text-align:center;color:var(--muted);font-size:13px">Carregando emendas ${_dashEmendaAno}…</div>`;
  dashboardAllEmendas = await getCached(parlId, ck, ()=>fetchAllPages(`/emendas/parlamentar/?parlamentar=${parlId}&ano=${_dashEmendaAno}`)).catch(()=>[]);
  section.innerHTML = buildDashEmendaHTML(dashboardAllEmendas, parlId);
  setTimeout(() => redrawDashEmendaCharts(dashboardAllEmendas), 0);
}

function redrawDashEmendaCharts(emendas) {
  const ids = ['chartEmendaFuncao','chartEmendaLocal'];
  ids.forEach(id=>{
    if(dashboardChartInstances[id]){try{dashboardChartInstances[id].destroy();}catch(e){} delete dashboardChartInstances[id];}
    const c=document.getElementById(id);
    if(c) c.style.display='';
    const w=c?.closest('[style*="height:220px"]');
    if(w){const m=w.querySelector('.chart-empty-msg');if(m)m.remove();}
  });

  if(!emendas.length || !document.getElementById('chartEmendaFuncao')) return;

  const lista = _dashEmendaFuncao ? emendas.filter(e=>e.funcao===_dashEmendaFuncao) : emendas;
  const COLORS=['#1A6B4F','#C9A84C','#3B82F6','#EC4899','#8B5CF6','#F59E0B','#10B981','#EF4444','#0EA5E9','#A855F7'];
  const font={family:"'Inter',sans-serif",size:11};
  const fmtK = v => v>=1e9?'R$'+(v/1e9).toFixed(1)+'B':v>=1e6?'R$'+(v/1e6).toFixed(1)+'M':v>=1e3?'R$'+(v/1e3).toFixed(0)+'K':'R$'+v.toFixed(0);

  function mountE(id,config){
    const canvas=document.getElementById(id);
    if(!canvas) return;
    dashboardChartInstances[id]=new Chart(canvas,config);
  }
  function showEmptyE(id){
    const canvas=document.getElementById(id);
    if(!canvas) return;
    canvas.style.display='none';
    const wrap=canvas.closest('[style*="height:220px"]');
    if(!wrap) return;
    if(!wrap.querySelector('.chart-empty-msg')){
      const d=document.createElement('div');
      d.className='chart-empty-msg';
      d.style.cssText='display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);font-size:12px;text-align:center;padding:8px';
      d.innerHTML='Nenhum dado para o filtro selecionado';
      wrap.appendChild(d);
    }
  }

  // Gráfico 1: Empenhado/Pago por Função
  const byFuncao={};
  lista.forEach(e=>{
    const f=e.funcao||'Não classificado';
    if(!byFuncao[f]) byFuncao[f]={emp:0,liq:0,pag:0};
    byFuncao[f].emp+=e.valor_empenhado||0;
    byFuncao[f].liq+=e.valor_liquidado||0;
    byFuncao[f].pag+=e.valor_pago||0;
  });
  const funcSorted=Object.entries(byFuncao).sort((a,b)=>b[1].emp-a[1].emp).slice(0,8);
  if(funcSorted.length){
    const fLabels=funcSorted.map(([f])=>f.length>22?f.slice(0,20)+'…':f);
    mountE('chartEmendaFuncao',{type:'bar',
      data:{
        labels:fLabels,
        datasets:[
          {label:'Empenhado',data:funcSorted.map(([,d])=>d.emp), backgroundColor:'#2563eb', borderRadius:3},
          {label:'Liquidado',data:funcSorted.map(([,d])=>d.liq), backgroundColor:'#d97706', borderRadius:3},
          {label:'Pago',     data:funcSorted.map(([,d])=>d.pag), backgroundColor:'#16a34a', borderRadius:3},
        ]
      },
      options:{responsive:true,maintainAspectRatio:false,
        plugins:{
          legend:{position:'top',labels:{font,boxWidth:10,padding:8}},
          tooltip:{callbacks:{label:ctx=>' '+fmtK(ctx.parsed.y)}},
          barValue:false,
        },
        scales:{
          x:{ticks:{font,maxRotation:45,minRotation:30},grid:{display:false}},
          y:{beginAtZero:true,ticks:{font,callback:v=>fmtK(v)},grid:{color:'rgba(0,0,0,.05)'}}
        }
      },
      plugins:[],
    });
  } else { showEmptyE('chartEmendaFuncao'); }

  // Gráfico 2: Top 10 Localidades por Empenhado (expande MÚLTIPLO pelos municípios reais)
  const byLocal={};
  lista.forEach(e=>{
    if(e.municipios && e.municipios.length>0){
      e.municipios.forEach(m=>{
        const l=(m.municipio||'Não informado')+(m.uf?' - '+m.uf:'');
        byLocal[l]=(byLocal[l]||0)+(m.emp||0);
      });
    } else {
      const l=e.localidade||'Não informado';
      byLocal[l]=(byLocal[l]||0)+(e.valor_empenhado||0);
    }
  });
  const localSorted=Object.entries(byLocal).filter(([,v])=>v>0).sort((a,b)=>b[1]-a[1]).slice(0,10);
  if(localSorted.length){
    const lLabels=localSorted.map(([l])=>l.length>28?l.slice(0,26)+'…':l);
    mountE('chartEmendaLocal',{type:'bar',
      data:{
        labels:lLabels,
        datasets:[{label:'Empenhado',data:localSorted.map(([,v])=>v),backgroundColor:lLabels.map((_,i)=>COLORS[i%COLORS.length]),borderRadius:3}]
      },
      options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,
        plugins:{
          legend:{display:false},
          tooltip:{callbacks:{label:ctx=>' '+fmtK(ctx.parsed.x)}},
          barValue:false,
        },
        layout:{padding:{right:72}},
        scales:{
          x:{beginAtZero:true,ticks:{font,callback:v=>fmtK(v)},grid:{color:'rgba(0,0,0,.05)'}},
          y:{ticks:{font},grid:{display:false}}
        }
      },
      plugins:[makeEmendaLabelPlugin(fmtK)],
    });
  } else { showEmptyE('chartEmendaLocal'); }
}

function semDados(msg){
  return `<div class="empty-tab"><i class="ph ph-magnifying-glass" style="font-size:28px;color:var(--muted);display:block;margin-bottom:10px"></i><p style="font-weight:600;color:var(--text);margin-bottom:4px">${msg}</p><p style="font-size:12px;color:var(--muted)">Nenhum registro encontrado para este parlamentar nesta fonte.</p></div>`;
}

function semModulo(msg){
  return `<div class="empty-tab"><i class="ph ph-prohibit" style="font-size:28px;color:#93c5fd;display:block;margin-bottom:10px"></i><p style="font-weight:600;color:var(--text);margin-bottom:4px">${msg}</p><p style="font-size:12px;color:var(--muted)">Esta fonte de dados não registra este tipo de informação.</p></div>`;
}

function getSourceCap() {
  return getCached('global', 'sourcecap_' + APP_CONFIG.source, async () => {
    // Câmara Federal: capabilities fixas — endpoints exigem parlamentar específico
    if(APP_CONFIG.source === 'camara_federal') {
      return {normas: true, comissoes: true, relatorias: false, frentes: true};
    }
    const check = async (path) => {
      try {
        const d = await fetchWithRetry(proxyUrl(path, {page:1}));
        return (d?.pagination?.total_entries ?? 0) > 0;
      } catch(e) { return true; }
    };
    const [normas, comissoes, relatorias, frentes] = await Promise.all([
      check('/norma/autorianorma/'),
      check('/comissoes/participacao/'),
      check('/materia/relatoria/'),
      check('/parlamentares/frenteparlamentar/'),
    ]);
    return {normas, comissoes, relatorias, frentes};
  });
}

// ── Tab: Início ──
async function renderTabInicio(p) {
  const bioRaw = formatBio(p.biografia||'');
  if(bioRaw){
    let h=`<h3 class="section-title">Biografia</h3>`;
    h+=`<div class="bio-text">${renderBioHtml(bioRaw)}</div>`;
    if(p.locais_atuacao) h+=`<div class="info-row" style="margin-top:16px"><strong>Locais de Atuação:</strong> ${esc(p.locais_atuacao)}</div>`;
    return h;
  }
  return semDados('Biografia não disponível para este parlamentar');
}

// ── Tab: Mandatos ──
async function renderTabMandatos(p) {
  const mandatos=await getCached(p.id,'mandatos',()=>fetchAllPages(`/parlamentares/mandato/?parlamentar=${p.id}`));
  mandatos.sort((a,b)=>(b.legislatura||0)-(a.legislatura||0));
  const lm={};allLegislaturas.forEach(l=>lm[l.id]=l);
  if(!mandatos.length) return semDados('Nenhum mandato encontrado');

  let h=`<h3 class="section-title">Total de Mandatos: ${mandatos.length}</h3>`;
  const thead='<tr><th>Legislatura</th><th>Votos Recebidos</th><th>Coligação</th><th>Titular</th></tr>';
  h+=paginateTable(mandatos,10,tablePages['pg-mandatos']||1,m=>{
    const l=lm[m.legislatura];
    const legLabel=l?`${l.numero}ª (${new Date(l.data_inicio).getFullYear()} - ${new Date(l.data_fim).getFullYear()})${l.id===allLegislaturas[0]?.id?' (Atual)':''}`:'#'+m.legislatura;
    return `<tr>
      <td><span class="td-leg">${legLabel}</span></td>
      <td><span class="td-votos">${m.votos_recebidos?Number(m.votos_recebidos).toLocaleString('pt-BR'):'—'}</span></td>
      <td style="color:#111827">${m.coligacao?'Coligação #'+m.coligacao:'—'}</td>
      <td><span class="td-titular ${m.titular?'yes':'no'}">${m.titular?'Sim':'Não'}</span></td>
    </tr>`;
  },thead,'pg-mandatos');
  return h;
}

// ── Tab: Matérias ──

// Extrai tipo, sigla, ano e label de um item autoria
// __str__ esperado: "NOME PARLAMENTAR - TIPO nº NUM de ANO"
const _tipoPattern = /^[A-Za-záàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇ][A-Za-záàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇ\s\.]*\s+(?:n[ºo°]|nº|\d)/i;
function extractMateriaInfo(a) {
  const raw = stripAutoria(a.__str__||'');
  const dash = raw.indexOf(' - ');
  let materiaStr, label;
  if (dash >= 0) {
    const before = raw.slice(0, dash);
    if (_tipoPattern.test(before)) { materiaStr = before; label = raw; }
    else { materiaStr = raw.slice(dash+3); label = materiaStr; }
  } else { materiaStr = raw; label = raw; }
  const m = materiaStr.match(/^([A-Za-záàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇ][A-Za-záàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇ\s\.]*?)\s+(?:n[ºo°]|nº|\d)/i);
  const tipoRaw = m ? m[1].trim().toUpperCase() : 'Outros';
  const sigla   = tipoNomesRev[tipoRaw] || tipoRaw;
  const ano     = extractYear(materiaStr) || '—';
  return {sigla, tipoRaw, tipoNome: tipoNomes[sigla]||tipoRaw, ano, label: label||`Matéria #${a.materia}`};
}

// Bloco agrupado por ano → tipo (Primeiro Autor ou Co-Autor)
function buildAutoriaGroup(title, items) {
  const byYear={};
  items.forEach(a=>{
    const {tipoRaw,sigla,tipoNome,ano}=extractMateriaInfo(a);
    if(!byYear[ano]) byYear[ano]={};
    if(!byYear[ano][tipoRaw]) byYear[ano][tipoRaw]={sigla,nome:tipoNome,count:0};
    byYear[ano][tipoRaw].count++;
  });
  const anos=Object.keys(byYear).sort((a,b)=>b-a);
  const isPrimeiro=title==='Primeiro Autor';
  let h=`<div style="margin-bottom:28px">`;
  h+=`<div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">${title}</div>`;
  h+='<div class="table-wrap"><table><tbody>';
  anos.forEach(ano=>{
    h+=`<tr><td colspan="3" style="background:var(--bg);padding:7px 12px;font-weight:700;font-size:13px">Ano: ${esc(String(ano))}</td></tr>`;
    const tiposRaw=Object.keys(byYear[ano]).sort((a,b)=>byYear[ano][b].count-byYear[ano][a].count);
    tiposRaw.forEach(tipoRaw=>{
      const {sigla,nome,count}=byYear[ano][tipoRaw];
      h+=`<tr class="tipo-row" onclick="showMateriaGrupo('${tipoRaw}','${String(ano)}',${isPrimeiro})" style="cursor:pointer">
        <td><strong>${esc(sigla)}</strong></td>
        <td>${esc(nome)}</td>
        <td style="text-align:right;font-weight:700;white-space:nowrap">${count}</td>
      </tr>`;
    });
  });
  h+='</tbody></table></div></div>';
  return h;
}

// Constrói o HTML completo da aba (título + grupos Primeiro Autor / Co-Autor)
function buildMateriasHtml(items, total, loading=false) {
  const isCamara = APP_CONFIG.source==='camara_federal';
  const itemLabel = isCamara ? 'proposição' : 'matéria';
  const itemLabelPl = isCamara ? 'proposições' : 'matérias';
  const titleLabel = isCamara ? 'Proposições Legislativas' : 'Matérias Legislativas';
  let h=`<h3 class="section-title">${titleLabel}</h3>`;
  h+=`<div style="font-size:13px;color:var(--muted);margin-bottom:20px">
    <strong style="color:var(--text)">${total.toLocaleString('pt-BR')}</strong> ${total!==1?itemLabelPl:itemLabel}
    ${loading?'<span id="mat-load-hint" style="font-size:11px;margin-left:10px;color:var(--accent)">· carregando...</span>':''}
  </div>`;
  if(loading&&!items.length){
    h+=`<div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--muted);padding:20px 0">
      <div class="spinner" style="width:14px;height:14px;border-width:2px;border-color:var(--accent);border-top-color:transparent"></div>
      Aguardando dados...
    </div>`;
    return h;
  }
  const primeiros=items.filter(a=>a.primeiro_autor);
  const coautores=items.filter(a=>!a.primeiro_autor);
  if(primeiros.length) h+=buildAutoriaGroup('Primeiro Autor',primeiros);
  if(coautores.length) h+=buildAutoriaGroup('Co-Autor',coautores);
  return h;
}

// Listagem ao clicar numa linha (tipoRaw + ano + isPrimeiro + page)
function showMateriaGrupo(tipoRaw, ano, isPrimeiro, page) {
  page = Math.max(1, page||1);
  const PER_PAGE = 10;
  const el=document.getElementById('tabContent');
  if(!el||!currentProfile) return;
  const cached=tabDataCache[currentProfile.id]?.mat_tipos;
  if(!cached) return;
  Promise.resolve(cached).then(({items})=>{
    const filtrado=items.filter(a=>{
      const info=extractMateriaInfo(a);
      return info.tipoRaw===tipoRaw && String(info.ano)===String(ano) && Boolean(a.primeiro_autor)===Boolean(isPrimeiro);
    });
    const totalPages=Math.max(1,Math.ceil(filtrado.length/PER_PAGE));
    page=Math.min(page,totalPages);
    const slice=filtrado.slice((page-1)*PER_PAGE, page*PER_PAGE);
    const isCamara = APP_CONFIG.source==='camara_federal';
    const sigla=tipoNomesRev[tipoRaw]||tipoRaw;
    const nome=tipoNomes[sigla]||tipoRaw;
    const autorLabel=isPrimeiro?'Primeiro Autor':'Co-Autor';
    let h=`<button onclick="voltarParaTipos()" style="display:inline-flex;align-items:center;gap:6px;margin-bottom:20px;padding:7px 14px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);font-size:13px;font-weight:600;font-family:inherit;cursor:pointer"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Voltar</button>`;
    h+=`<h3 class="section-title" style="margin-bottom:4px">${esc(sigla)} <span style="color:var(--muted);font-weight:400">— ${esc(nome||sigla)}</span></h3>`;
    h+=`<div style="font-size:13px;color:var(--muted);margin-bottom:16px">${esc(String(ano))} · ${esc(autorLabel)} · ${filtrado.length.toLocaleString('pt-BR')} ${isCamara?'proposição':'matéria'}${filtrado.length!==1?'s':''}</div>`;
    h+=`<div class="table-wrap"><table><thead><tr><th>${isCamara?'Proposição':'Matéria'}</th><th>Autoria</th></tr></thead><tbody>`;
    slice.forEach(a=>{
      const {label}=extractMateriaInfo(a);
      h+=`<tr>
        <td><a href="javascript:void(0)" onclick="openMateria(${a.materia})" style="color:var(--accent);font-weight:500">${esc(label)}</a></td>
        <td><span class="td-titular ${a.primeiro_autor?'yes':'no'}">${a.primeiro_autor?'1º Autor':'Co-autor'}</span></td>
      </tr>`;
    });
    h+='</tbody></table></div>';
    if(totalPages>1){
      h+=`<div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap">`;
      h+=`<button onclick="showMateriaGrupo('${tipoRaw}','${String(ano)}',${isPrimeiro},${page-1})" ${page<=1?'disabled':''} style="padding:5px 12px;border-radius:6px;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);font-size:13px;cursor:pointer;font-family:inherit">‹ Anterior</button>`;
      const start=Math.max(1,page-2), end=Math.min(totalPages,page+2);
      if(start>1) h+=`<button onclick="showMateriaGrupo('${tipoRaw}','${String(ano)}',${isPrimeiro},1)" style="padding:5px 10px;border-radius:6px;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);font-size:13px;cursor:pointer;font-family:inherit">1</button>`;
      if(start>2) h+=`<span style="color:var(--muted);font-size:13px">…</span>`;
      for(let i=start;i<=end;i++){
        const active=i===page;
        h+=`<button onclick="showMateriaGrupo('${tipoRaw}','${String(ano)}',${isPrimeiro},${i})" style="padding:5px 10px;border-radius:6px;border:1.5px solid ${active?'var(--accent)':'var(--border)'};background:${active?'var(--accent)':'var(--surface)'};color:${active?'#fff':'var(--muted)'};font-size:13px;font-weight:${active?700:400};cursor:pointer;font-family:inherit">${i}</button>`;
      }
      if(end<totalPages-1) h+=`<span style="color:var(--muted);font-size:13px">…</span>`;
      if(end<totalPages) h+=`<button onclick="showMateriaGrupo('${tipoRaw}','${String(ano)}',${isPrimeiro},${totalPages})" style="padding:5px 10px;border-radius:6px;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);font-size:13px;cursor:pointer;font-family:inherit">${totalPages}</button>`;
      h+=`<button onclick="showMateriaGrupo('${tipoRaw}','${String(ano)}',${isPrimeiro},${page+1})" ${page>=totalPages?'disabled':''} style="padding:5px 12px;border-radius:6px;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);font-size:13px;cursor:pointer;font-family:inherit">Próximo ›</button>`;
      h+=`<span style="font-size:12px;color:var(--muted);margin-left:4px">Pág. ${page}/${totalPages}</span>`;
      h+='</div>';
    }
    el.innerHTML=h;
  });
}

function voltarParaTipos() { switchTab('materias'); }

async function renderTabMaterias(p) {
  const autorData = await getAutorData(p);
  if(!autorData) return `<div class="empty-tab"><i class="ph ph-user-x" style="font-size:28px;color:var(--muted);display:block;margin-bottom:10px"></i><p style="font-weight:600;color:var(--text);margin-bottom:4px">Autor não localizado na API</p><p style="font-size:12px;color:var(--muted)">O parlamentar não foi encontrado no sistema de autoria do SAPL. Isso pode ocorrer quando o nome cadastrado difere da base legislativa.</p></div>`;

  // Carrega nomes dos tipos uma vez por sessão
  if(!Object.keys(tipoNomes).length) {
    try {
      const tipos = await getCached('global','tipomateria',()=>fetchAllPages('/materia/tipomateria/'));
      tipos.forEach(t=>{ if(t.sigla){ const s=t.sigla.toUpperCase(),d=(t.descricao||t.sigla).toUpperCase(); tipoNomes[s]=d; tipoNomesRev[d]=s; } });
    } catch(e) {}
  }

  // Caminho rápido: dados já em cache (memória ou sessionStorage)
  if(!tabDataCache[p.id]?.mat_tipos) {
    const stored = sessionTabLoad(p.id, 'mat_tipos');
    if(stored !== null) {
      if(!tabDataCache[p.id]) tabDataCache[p.id]={};
      tabDataCache[p.id].mat_tipos = Promise.resolve(stored);
    }
  }
  if(tabDataCache[p.id]?.mat_tipos) {
    const {items, total} = await tabDataCache[p.id].mat_tipos;
    return buildMateriasHtml(items, total, false);
  }

  // Busca primeira página
  const basePath = `/materia/autoria/?autor=${autorData.id}&o=-id`;
  let firstData;
  try { firstData = await fetchWithRetry(proxyUrl(basePath, {page:1})); }
  catch(e) { return semDados('Erro ao carregar matérias'); }
  if(firstData?.__rate_limited) throw new Error('Servidor legislativo está limitando requisições. Aguarde alguns minutos e tente novamente.');

  const firstResults = firstData?.results || [];
  const total        = firstData?.pagination?.total_entries || firstResults.length;
  const totalPages   = firstData?.pagination?.total_pages   || 1;

  if(!firstResults.length) {
    if(!tabDataCache[p.id]) tabDataCache[p.id]={};
    tabDataCache[p.id].mat_tipos = Promise.resolve({items:[],total:0});
    return semDados('Nenhuma matéria encontrada');
  }

  if(!tabDataCache[p.id]) tabDataCache[p.id]={};

  if(totalPages <= 1) {
    tabDataCache[p.id].mat_tipos = Promise.resolve({items:firstResults, total});
    return buildMateriasHtml(firstResults, total, false);
  }

  // Carrega restante em segundo plano com atualizações progressivas
  const parlId = p.id;

  const allPromise = (async () => {
    const remaining = await fetchAllPages(basePath, null, 400, firstData, (allItems) => {
      // onBatch recebe o array completo acumulado até agora
      if(activeTab!=='materias' || currentProfile?.id!==parlId) return;
      const el=document.getElementById('tabContent');
      if(el) el.innerHTML=buildMateriasHtml(allItems, total, true);
    });
    return {items: remaining, total};
  })();

  tabDataCache[p.id].mat_tipos = allPromise.catch(e => {
    delete tabDataCache[p.id]?.mat_tipos; throw e;
  });

  // Quando terminar: salva no sessionStorage e re-renderiza sem o banner
  allPromise.then(({items}) => {
    sessionTabSave(parlId, 'mat_tipos', {items, total});
    if(activeTab!=='materias' || currentProfile?.id!==parlId) return;
    const el=document.getElementById('tabContent');
    if(el) el.innerHTML=buildMateriasHtml(items, total, false);
  }).catch(()=>{});

  // Exibe primeira página imediatamente com banner "carregando..."
  return buildMateriasHtml(firstResults, total, totalPages > 1);
}

// ── Matéria Detail ──
async function openMateria(materiaId) {
  const main=document.getElementById("mainContent");
  main.innerHTML='<div class="loader"><div class="spinner"></div><span style="color:var(--muted);font-size:14px">Carregando matéria...</span></div>';
  window.scrollTo({top:0,behavior:"smooth"});

  try{
    const isCamara = APP_CONFIG.source === 'camara_federal';
    const [m, tramitacoes, autores, docs, temas] = await Promise.all([
      fetchWithRetry(proxyUrl(`/materia/materialegislativa/${materiaId}/`)),
      fetchAllPages(`/materia/tramitacao/?materia=${materiaId}`).catch(()=>[]),
      fetchAllPages(`/materia/autoria/?materia=${materiaId}`).catch(()=>[]),
      fetchAllPages(`/materia/documentosacessorio/?materia=${materiaId}`).catch(()=>[]),
      isCamara ? fetchAllPages(`/materia/tema/?materia=${materiaId}`).catch(()=>[]) : Promise.resolve([]),
    ]);
    if(!m||!m.id) throw new Error("Matéria não encontrada");

    tramitacoes.sort((a,b)=>(b.data_tramitacao||'').localeCompare(a.data_tramitacao||''));

    let h='<div style="padding-top:28px;padding-bottom:60px">';
    h+='<button class="profile-back" onclick="closeMateria()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Voltar</button>';

    // ── Extrai campos de tipo e regime para usar no header e no card ─────────
    const isCamaraDetail = APP_CONFIG.source==='camara_federal';
    const tipoSigla=(()=>{const s=m.tipo?.sigla||m.tipo?.descricao||(typeof m.tipo==='string'?m.tipo:'')||'';if(s)return s;const raw=stripAutoria(m.__str__||'');const d=raw.indexOf(' - ');const base=d>=0?raw.slice(d+3):raw;return base.split(' nº')[0].trim();})();
    const tipoDescr=m.tipo?.descricao&&m.tipo.descricao!==m.tipo?.sigla?m.tipo.descricao:'';
    const regimeVal=(()=>{if(!m.regime_tramitacao)return '';return typeof m.regime_tramitacao==='object'?(m.regime_tramitacao?.descricao||''):({1:'Normal',2:'Urgência'}[m.regime_tramitacao]??'Regime '+m.regime_tramitacao);})();
    const textoUrl=m.texto_original?(m.texto_original.startsWith('http')?m.texto_original:API_BASE+m.texto_original):null;

    // ── Header: título + badge + botão documento ──────────────────────────────
    h+='<div class="materia-header">';
    const materiaTitle=(()=>{const raw=stripAutoria(m.__str__||'');const d=raw.indexOf(' - ');return d>=0?raw.slice(d+3):raw;})();
    h+=`<h1 class="materia-title">${esc(materiaTitle||m.__str__||'Matéria #'+m.id)}</h1>`;
    h+='<div style="display:flex;align-items:center;gap:8px;flex-shrink:0">';
    if(m.em_tramitacao!=null){
      h+=`<span class="tag" style="background:${m.em_tramitacao?'var(--accent-light)':'var(--red-light)'};color:${m.em_tramitacao?'var(--accent)':'var(--red)'}">${m.em_tramitacao?'Em Tramitação':'Encerrada'}</span>`;
    }
    if(textoUrl) h+=`<a href="${esc(textoUrl)}" target="_blank" class="btn-pdf" style="white-space:nowrap"><i class="ph ph-file-pdf"></i> Documento</a>`;
    h+='</div></div>';

    // ── Card principal ────────────────────────────────────────────────────────
    h+='<div class="materia-card">';

    // Tipo em destaque
    if(tipoSigla){
      h+=`<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border)">`;
      h+=`<span style="font-size:28px;font-weight:800;color:var(--accent);font-family:'Inter',sans-serif;line-height:1">${esc(tipoSigla)}</span>`;
      if(tipoDescr) h+=`<span style="font-size:13px;color:var(--muted);line-height:1.4">${esc(tipoDescr)}</span>`;
      h+=`</div>`;
    }

    // Números: número, ano, data
    h+=`<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:16px;margin-bottom:20px">`;
    if(m.numero) h+=`<div><span class="materia-label">Número</span><div style="font-size:20px;font-weight:700;color:var(--text);margin-top:4px">${esc(m.numero)}</div></div>`;
    if(m.ano)    h+=`<div><span class="materia-label">Ano</span><div style="font-size:20px;font-weight:700;color:var(--text);margin-top:4px">${m.ano}</div></div>`;
    if(m.data_apresentacao) h+=`<div><span class="materia-label">Apresentação</span><div style="font-size:14px;color:var(--text);margin-top:4px;font-weight:500">${fmtDate(m.data_apresentacao)}</div></div>`;
    if(m.numero_protocolo)  h+=`<div><span class="materia-label">Protocolo</span><div style="font-size:14px;color:var(--text);margin-top:4px">${esc(m.numero_protocolo)}</div></div>`;
    h+=`</div>`;

    // Status: situação + órgão + regime
    const hasStatus=m.situacao||m.orgao_atual||regimeVal;
    if(hasStatus){
      h+=`<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;padding:16px;background:var(--bg);border-radius:10px;margin-bottom:20px">`;
      if(m.situacao)   h+=`<div><span class="materia-label">Situação</span><div style="margin-top:6px"><span class="tag" style="background:${m.em_tramitacao?'var(--accent-light)':'#f3f4f6'};color:${m.em_tramitacao?'var(--accent)':'var(--muted)'};font-size:12px">${esc(m.situacao)}</span></div></div>`;
      if(m.orgao_atual) h+=`<div><span class="materia-label">Órgão Atual</span><div style="margin-top:4px;font-size:14px;font-weight:600;color:var(--text)">${esc(m.orgao_atual)}</div></div>`;
      if(regimeVal)     h+=`<div><span class="materia-label">Regime</span><div style="margin-top:4px;font-size:13px;color:var(--text)">${esc(regimeVal)}</div></div>`;
      h+=`</div>`;
    }

    // Último despacho
    if(m.despacho_atual){
      h+=`<div style="padding:12px 16px;border-left:3px solid var(--accent);background:var(--accent-light);border-radius:0 8px 8px 0;margin-bottom:20px">`;
      h+=`<span class="materia-label">Último Despacho</span>`;
      h+=`<div style="margin-top:4px;font-size:13px;line-height:1.6;color:var(--text)">${esc(m.despacho_atual)}</div>`;
      h+=`</div>`;
    }

    // Ementa
    h+=`<div>`;
    h+=`<span class="materia-label">Ementa</span>`;
    if(m.ementa){
      h+=`<div class="materia-ementa">${esc(m.ementa)}</div>`;
    }else{
      h+=`<div style="margin-top:4px;color:var(--muted);font-size:13px;font-style:italic">Não disponível para esta fonte legislativa.</div>`;
    }
    h+=`</div>`;

    // Palavras-chave
    if(m.palavras_chave){
      const kws=m.palavras_chave.split(/[,_]/).map(k=>k.trim()).filter(Boolean);
      if(kws.length) h+=`<div style="margin-top:16px"><span class="materia-label">Palavras-chave</span><div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px">${kws.map(k=>`<span style="padding:3px 10px;border-radius:20px;background:#f3f4f6;color:var(--muted);font-size:12px;font-weight:500">${esc(k)}</span>`).join('')}</div></div>`;
    }

    h+=`</div>`;

    if(autores.length>0){
      const AUTORES_POR_PAG=8;
      const hasExtra=autores.some(a=>a.tipo||a.partido);
      window._autoresPaged={lista:autores,hasExtra,pag:0,porPag:AUTORES_POR_PAG};
      const totalPags=Math.ceil(autores.length/AUTORES_POR_PAG);

      function buildAutoresRows(pag){
        const slice=autores.slice(pag*AUTORES_POR_PAG,(pag+1)*AUTORES_POR_PAG);
        return slice.map(a=>{
          const nome=(a.__str__||'').replace(/^Autoria:.*?-\s*/,'').trim()||a.__str__||'—';
          const partyUf=[a.partido,a.uf].filter(Boolean).join('/');
          const nomeHtml=`<span style="font-weight:500;color:#111827">${esc(nome)}</span>${partyUf?`<span style="font-size:11px;color:var(--muted);display:block">${esc(partyUf)}</span>`:''}`;
          const tipoHtml=hasExtra?`<td style="font-size:12px;color:var(--muted)">${esc(a.tipo||'—')}</td>`:'';
          const papel=a.primeiro_autor?'Proponente':'Coautor';
          return `<tr><td>${nomeHtml}</td>${tipoHtml}<td><span class="td-titular ${a.primeiro_autor?'yes':'no'}">${papel}</span></td></tr>`;
        }).join('');
      }

      window._goAutoresPag=function(dir){
        const s=window._autoresPaged;
        s.pag=Math.max(0,Math.min(Math.ceil(s.lista.length/s.porPag)-1,s.pag+dir));
        document.getElementById('autores-tbody').innerHTML=buildAutoresRows(s.pag);
        document.getElementById('autores-pag-info').textContent=`Página ${s.pag+1} de ${Math.ceil(s.lista.length/s.porPag)}`;
        document.getElementById('autores-prev').disabled=s.pag===0;
        document.getElementById('autores-next').disabled=s.pag>=Math.ceil(s.lista.length/s.porPag)-1;
      };

      h+='<div class="materia-card">';
      h+=`<h3 class="section-title">Autores (${autores.length})</h3>`;
      h+=`<div class="table-wrap"><table><thead><tr><th>Autor</th>${hasExtra?'<th>Tipo</th>':''}<th>Papel</th></tr></thead><tbody id="autores-tbody">`;
      h+=buildAutoresRows(0);
      h+='</tbody></table></div>';
      if(totalPags>1){
        h+=`<div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:10px;font-size:13px">`;
        h+=`<button id="autores-prev" onclick="_goAutoresPag(-1)" disabled style="padding:4px 12px;border:1px solid var(--border);border-radius:6px;background:#fff;cursor:pointer;font-size:13px">‹ Anterior</button>`;
        h+=`<span id="autores-pag-info" style="color:var(--muted)">Página 1 de ${totalPags}</span>`;
        h+=`<button id="autores-next" onclick="_goAutoresPag(1)" style="padding:4px 12px;border:1px solid var(--border);border-radius:6px;background:#fff;cursor:pointer;font-size:13px">Próxima ›</button>`;
        h+='</div>';
      }
      h+='</div>';
    }

    if(temas&&temas.length>0){
      h+='<div class="materia-card">';
      h+=`<h3 class="section-title">Temas</h3>`;
      h+='<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">';
      temas.forEach(t=>{h+=`<span style="padding:4px 12px;border-radius:20px;background:var(--accent-light);color:var(--accent);font-size:12px;font-weight:500">${esc(t.tema)}</span>`;});
      h+='</div></div>';
    }

    if(tramitacoes.length>0){
      h+='<div class="materia-card">';
      h+=`<h3 class="section-title">Histórico de Tramitação (${tramitacoes.length})</h3>`;
      h+='<div class="tram-timeline">';
      tramitacoes.forEach((t,i)=>{
        const statusStr=typeof t.status==='string'?t.status:(t.status?.__str__||'');
        let destStr='';
        if(t.unidade_tramitacao_destino){const d=t.unidade_tramitacao_destino;destStr=typeof d==='string'?d:(d.__str__||d.nome||'');}
        const textoStr=t.texto||'';
        const regimeStr=t.regime||'';
        h+=`<div class="tram-item${i===0?' tram-latest':''}"><div class="tram-dot"></div><div class="tram-content">`;
        h+=`<div class="tram-date">${fmtDate(t.data_tramitacao)}</div>`;
        if(statusStr) h+=`<div class="tram-status">${esc(statusStr)}</div>`;
        if(regimeStr) h+=`<div class="tram-dest" style="color:var(--muted)">Regime: ${esc(regimeStr)}</div>`;
        if(destStr)   h+=`<div class="tram-dest">Destino: ${esc(destStr)}</div>`;
        if(textoStr)  h+=`<div class="tram-texto">${esc(textoStr)}</div>`;
        if(t.url)     h+=`<a href="${esc(t.url)}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:4px;font-size:12px;color:var(--accent);margin-top:4px"><i class="ph ph-file-text"></i> Ver documento</a>`;
        h+='</div></div>';
      });
      h+='</div></div>';
    }

    if(docs.length>0){
      h+='<div class="materia-card">';
      h+=`<h3 class="section-title">Documentos Acessórios (${docs.length})</h3>`;
      h+='<div class="table-wrap"><table><thead><tr><th>Documento</th><th>Tipo</th><th>Data</th></tr></thead><tbody>';
      docs.forEach(d=>{
        const fileUrl=d.arquivo?(d.arquivo.startsWith('http')?d.arquivo:API_BASE+d.arquivo):null;
        const nome=d.nome||d.__str__||'Documento';
        const tipo=typeof d.tipo==='object'?(d.tipo?.__str__||d.tipo?.descricao||'—'):(d.tipo||'—');
        h+=`<tr>`;
        h+=fileUrl?`<td><a href="${esc(fileUrl)}" target="_blank" style="color:var(--accent);font-weight:500"><i class="ph ph-file-pdf"></i> ${esc(nome)}</a></td>`:`<td style="color:#111827">${esc(nome)}</td>`;
        h+=`<td style="color:#111827">${esc(tipo)}</td><td style="color:#111827">${fmtDate(d.data)}</td></tr>`;
      });
      h+='</tbody></table></div></div>';
    }

    {
      const gridFields=[
        ['Apelido',m.apelido],['Objeto',m.objeto],['Resultado',m.resultado],
        ['Data de Publicação',m.data_publicacao?fmtDate(m.data_publicacao):null],
        ['Data de Vigência',m.data_vigencia?fmtDate(m.data_vigencia):null],
        ['Nº Origem Externa',m.numero_origem_externa],
        ['Data Origem Externa',m.data_origem_externa?fmtDate(m.data_origem_externa):null],
        ['Local Origem Externa',m.local_origem_externa],['Apreciação',m.apreciacao],
        ['Complementar?',m.complementar!=null?(m.complementar?'Sim':'Não'):null],
        ['Matéria Polêmica?',m.polemica!=null?(m.polemica?'Sim':'Não'):null],
      ];
      const textFields=[
        ['Ementa Diário',m.ementa_diario],['Legislação Citada',m.legislacao_citada],
        ['Indexação',m.indexacao],['Tipificação Textual',m.tipificacao_textual],['Observação',m.observacao],
      ];
      const hasAny=gridFields.some(([,v])=>v!=null&&v!=='')||textFields.some(([,v])=>v);
      if(hasAny){
        h+='<div class="materia-card"><h3 class="section-title">Outras Informações</h3>';
        h+='<div class="materia-grid">';
        gridFields.forEach(([label,val])=>{
          if(val!=null&&val!=='') h+=`<div class="materia-field"><span class="materia-label">${label}</span><div class="materia-value">${esc(String(val))}</div></div>`;
        });
        h+='</div>';
        textFields.forEach(([label,val])=>{
          if(val) h+=`<div style="margin-top:16px"><span class="materia-label">${label}</span><div style="margin-top:4px;color:#111827;font-size:13px;line-height:1.6">${esc(val)}</div></div>`;
        });
        h+='</div>';
      }
    }

    h+='</div>';
    main.innerHTML=h;
  }catch(e){
    console.error(e);
    main.innerHTML=`<div class="empty"><p style="color:var(--red);font-weight:600">Erro ao carregar matéria</p><p style="margin-top:8px;color:var(--muted)">${esc(e.message)}</p><button class="profile-back" onclick="closeMateria()" style="margin-top:16px">← Voltar</button></div>`;
  }
}

function closeMateria() {
  if(currentProfile){
    const main=document.getElementById("mainContent");
    renderProfileShell(currentProfile).then(html=>{
      main.innerHTML=html;
      switchTab('materias');
    });
  }else{backToList()}
}

// ── Tab: Normas (agrupadas por tipo + PDF) ──
function extractNormaInfo(n) {
  const raw = stripAutoria(n.__str__||'');
  const dash = raw.indexOf(' - ');
  let normaStr;
  if (dash >= 0) {
    const before = raw.slice(0, dash);
    normaStr = _tipoPattern.test(before) ? before : raw.slice(dash+3);
  } else { normaStr = raw; }
  const m = normaStr.match(/^([A-Za-záàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇ][A-Za-záàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇ\s\.]*?)\s+(?:n[ºo°]|nº|\d)/i);
  const tipoRaw = m ? m[1].trim().toUpperCase() : 'Outros';
  const sigla   = normaTipoNomesRev[tipoRaw] || tipoRaw;
  const nome    = normaTipoNomes[sigla] || tipoRaw;
  const ano     = extractYear(normaStr) || '—';
  return {tipoRaw, sigla, nome, ano, label: normaStr || `Norma #${n.norma}`};
}

function buildNormaGroup(title, items) {
  const byYear={};
  items.forEach(n=>{
    const {tipoRaw,sigla,nome,ano}=extractNormaInfo(n);
    if(!byYear[ano]) byYear[ano]={};
    if(!byYear[ano][tipoRaw]) byYear[ano][tipoRaw]={sigla,nome,count:0};
    byYear[ano][tipoRaw].count++;
  });
  const anos=Object.keys(byYear).sort((a,b)=>b-a);
  const isPrimeiro=title==='Primeiro Autor';
  let h=`<div style="margin-bottom:28px">`;
  h+=`<div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">${title}</div>`;
  h+='<div class="table-wrap"><table><tbody>';
  anos.forEach(ano=>{
    h+=`<tr><td colspan="3" style="background:var(--bg);padding:7px 12px;font-weight:700;font-size:13px">Ano: ${esc(String(ano))}</td></tr>`;
    const tiposRaw=Object.keys(byYear[ano]).sort((a,b)=>byYear[ano][b].count-byYear[ano][a].count);
    tiposRaw.forEach(tipoRaw=>{
      const {sigla,nome,count}=byYear[ano][tipoRaw];
      h+=`<tr class="tipo-row" onclick="showNormaGrupo('${tipoRaw}','${String(ano)}',${isPrimeiro},1)" style="cursor:pointer">
        <td><strong>${esc(sigla)}</strong></td>
        <td>${esc(nome)}</td>
        <td style="text-align:right;font-weight:700;white-space:nowrap">${count}</td>
      </tr>`;
    });
  });
  h+='</tbody></table></div></div>';
  return h;
}

async function renderTabNormas(p) {
  const autorData = await getAutorData(p);
  if(!autorData) return '<div class="empty-tab">Autor não encontrado.</div>';

  // Carrega tipos de norma uma vez por sessão
  if(!Object.keys(normaTipoNomes).length) {
    try {
      const tipos = await getCached('global','tiponorma',()=>fetchAllPages('/norma/tiponormajuridica/'));
      tipos.forEach(t=>{ if(t.sigla){ const s=t.sigla.toUpperCase(),d=(t.descricao||t.sigla).toUpperCase(); normaTipoNomes[s]=d; normaTipoNomesRev[d]=s; } });
    } catch(e) {}
  }

  const isCamara = APP_CONFIG.source==='camara_federal';
  const [normas, capN]=await Promise.all([
    getCached(p.id,'normas',()=>fetchAllPages(`/norma/autorianorma/?autor=${autorData.id}`)),
    getSourceCap(),
  ]);
  if(!normas.length) {
    if(isCamara||capN.normas) return semDados('Nenhuma norma jurídica / proposição sancionada encontrada para este parlamentar');
    return semModulo('Esta fonte não utiliza Normas Jurídicas');
  }

  const primeiros=normas.filter(n=>n.primeiro_autor);
  const coautores=normas.filter(n=>!n.primeiro_autor);

  const normaTitle = isCamara ? 'Normas Sancionadas' : 'Normas Jurídicas';
  const normaSubtitle = isCamara ? '<div style="font-size:12px;color:var(--muted);margin-top:2px">Proposições de autoria do(a) parlamentar que foram aprovadas e se tornaram lei</div>' : '';
  let h=`<h3 class="section-title">${normaTitle}</h3>${normaSubtitle}`;
  h+=`<div style="font-size:13px;color:var(--muted);margin-bottom:20px;margin-top:8px">
    <strong style="color:var(--text)">${normas.length.toLocaleString('pt-BR')}</strong> norma${normas.length!==1?'s':(isCamara?' sancionada':'')}
  </div>`;
  if(primeiros.length) h+=buildNormaGroup('Primeiro Autor', primeiros);
  if(coautores.length) h+=buildNormaGroup('Co-Autor', coautores);
  return h;
}

// ══════════════════════════════════════════════════════
// TAB: EMENDAS (Câmara Federal)
// ══════════════════════════════════════════════════════
let _emendaYear   = new Date().getFullYear();
let _emendaFuncao = '';
let _emendaOrgao  = '';
let _emendaLocal  = '';

async function renderTabEmendas(p) {
  const fmtBRL = v => v>0 ? 'R$ '+v.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}) : '—';
  const pct    = (v,total) => total>0 ? Math.round(v/total*100) : 0;

  const anoHoje = new Date().getFullYear();
  const anos = [];
  for(let y=anoHoje; y>=2015; y--) anos.push(y);

  const emendas = await getCached(p.id,`emendas_${_emendaYear}`,()=>
    fetchAllPages(`/emendas/parlamentar/?parlamentar=${p.id}&ano=${_emendaYear}`)
  );

  // Opções únicas para filtros
  const funcoes    = [...new Set(emendas.map(e=>e.funcao||'').filter(Boolean))].sort();
  const orgaos     = [...new Set(emendas.map(e=>e.orgao||'').filter(Boolean))].sort();
  const localidades= [...new Set(emendas.map(e=>e.localidade||'').filter(Boolean))].sort();

  // Aplica filtros
  const lista = emendas.filter(e=>
    (!_emendaFuncao || e.funcao===_emendaFuncao) &&
    (!_emendaOrgao  || e.orgao===_emendaOrgao)   &&
    (!_emendaLocal  || e.localidade===_emendaLocal)
  );

  const totalDot = lista.reduce((s,e)=>s+(e.valor_dotacao||0),0);
  const totalEmp = lista.reduce((s,e)=>s+(e.valor_empenhado||0),0);
  const totalLiq = lista.reduce((s,e)=>s+(e.valor_liquidado||0),0);
  const totalPag = lista.reduce((s,e)=>s+(e.valor_pago||0),0);

  const selStyle = 'padding:5px 10px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface);font-size:12px;font-family:inherit;color:var(--text);max-width:160px';

  let h='<div>';

  // ── Cabeçalho + ano ────────────────────────────────
  h+=`<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px">`;
  h+=`<h3 class="section-title" style="margin:0">Emendas Parlamentares</h3>`;
  h+=`<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">`;
  h+=`<span style="font-size:12px;color:var(--muted);font-weight:600">Ano:</span>`;
  h+=`<select onchange="switchEmendaAno(${p.id},this.value)" style="${selStyle}">`;
  anos.forEach(y=>{ h+=`<option value="${y}"${y===_emendaYear?' selected':''}>${y}</option>`; });
  h+=`</select>`;

  // Filtro funcao
  h+=`<select onchange="filterEmendas(${p.id},'funcao',this.value)" style="${selStyle}" title="Filtrar por função">`;
  h+=`<option value="">Todas as funções</option>`;
  funcoes.forEach(f=>{ h+=`<option value="${esc(f)}"${_emendaFuncao===f?' selected':''}>${esc(f)}</option>`; });
  h+=`</select>`;

  // Filtro região (orgao = col 11 do CSV)
  if(orgaos.length){
    h+=`<select onchange="filterEmendas(${p.id},'orgao',this.value)" style="${selStyle}" title="Filtrar por região">`;
    h+=`<option value="">Todas as regiões</option>`;
    orgaos.forEach(o=>{ h+=`<option value="${esc(o)}"${_emendaOrgao===o?' selected':''}>${esc(o)}</option>`; });
    h+=`</select>`;
  }

  // Filtro localidade
  if(localidades.length){
    h+=`<select onchange="filterEmendas(${p.id},'local',this.value)" style="${selStyle}" title="Filtrar por localidade">`;
    h+=`<option value="">Todas as localidades</option>`;
    localidades.forEach(l=>{ h+=`<option value="${esc(l)}"${_emendaLocal===l?' selected':''}>${esc(l.length>28?l.slice(0,28)+'…':l)}</option>`; });
    h+=`</select>`;
  }

  if(_emendaFuncao||_emendaOrgao||_emendaLocal){
    h+=`<button onclick="clearEmendaFiltros(${p.id})" style="padding:5px 10px;border-radius:8px;border:1.5px solid var(--border);background:none;font-size:12px;font-family:inherit;color:var(--muted);cursor:pointer">× Limpar</button>`;
  }
  h+=`</div></div>`;

  if(!emendas.length){
    h+=`<div class="empty-tab" style="padding:40px 0">
      <i class="ph ph-money" style="font-size:32px;color:var(--muted);opacity:.4;display:block;margin-bottom:12px"></i>
      <p style="font-weight:600;color:var(--text);margin-bottom:4px">Nenhuma emenda encontrada em ${_emendaYear}</p>
      <p style="font-size:12px;color:var(--muted)">Tente selecionar outro ano ou verifique se o parlamentar apresentou emendas nesse período.</p>
    </div>`;
    h+='</div>';
    return h;
  }

  if(!lista.length){
    h+=`<div class="empty-tab" style="padding:30px 0">
      <p style="font-size:14px;color:var(--muted)">Nenhuma emenda para os filtros selecionados.</p>
    </div></div>`;
    return h;
  }

  // ── KPIs ───────────────────────────────────────────
  h+=`<div class="kpi-row" style="margin-bottom:16px">`;
  h+=`<div class="kpi-card"><div class="kpi-value">${lista.length}</div><div class="kpi-label">Emendas${lista.length<emendas.length?' (filtrado)':''}</div></div>`;
  h+=`<div class="kpi-card"><div class="kpi-value" style="font-size:13px;color:#2563eb">${fmtBRL(totalEmp)}</div><div class="kpi-label">Empenhado</div></div>`;
  h+=`<div class="kpi-card"><div class="kpi-value" style="font-size:13px;color:#d97706">${fmtBRL(totalLiq)}</div><div class="kpi-label">Liquidado</div></div>`;
  h+=`<div class="kpi-card"><div class="kpi-value" style="font-size:13px;color:var(--accent)">${fmtBRL(totalPag)}</div><div class="kpi-label">Pago</div></div>`;
  h+='</div>';

  // ── Barra de execução (Emp como base 100%) ──────────
  if(totalEmp>0){
    const pLiq = pct(totalLiq,totalEmp);
    const pPag = pct(totalPag,totalEmp);
    h+=`<div style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px">`;
    h+=`<div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">Execução (% do empenhado)</div>`;
    const barRow = (label, valor, perc, cor) =>
      `<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <span style="min-width:80px;font-size:12px;color:var(--muted)">${label}</span>
        <div style="flex:1;height:10px;background:#f3f4f6;border-radius:99px;overflow:hidden">
          <div style="width:${perc}%;height:100%;background:${cor};border-radius:99px;transition:width .4s"></div>
        </div>
        <span style="min-width:44px;text-align:right;font-size:12px;font-weight:600;color:${cor}">${perc}%</span>
        <span style="font-size:11px;color:var(--muted);min-width:120px;text-align:right">${fmtBRL(valor)}</span>
      </div>`;
    h+=barRow('Liquidado', totalLiq, pLiq, '#d97706');
    h+=barRow('Pago',      totalPag, pPag, '#16a34a');
    h+=`</div>`;
  }

  // ── Agrupamento por função ──────────────────────────
  const porFunc = {};
  lista.forEach(e=>{
    const f=e.funcao||'Não classificado';
    if(!porFunc[f]) porFunc[f]={total:0,emp:0,liq:0,pag:0,itens:[]};
    porFunc[f].total++;
    porFunc[f].emp+=e.valor_empenhado||0;
    porFunc[f].liq+=e.valor_liquidado||0;
    porFunc[f].pag+=e.valor_pago||0;
    porFunc[f].itens.push(e);
  });
  const funcs = Object.entries(porFunc).sort((a,b)=>b[1].emp-a[1].emp);

  h+=`<div class="materia-card">`;
  h+=`<h3 class="section-title">Por Função/Tema (${funcs.length})</h3>`;
  funcs.forEach(([func,dados])=>{
    const statusCor = dados.pag>0 ? '#16a34a' : dados.liq>0 ? '#d97706' : dados.emp>0 ? '#2563eb' : '#9ca3af';
    const statusLabel = dados.pag>0 ? 'Pago' : dados.liq>0 ? 'Liquidado' : dados.emp>0 ? 'Empenhado' : 'Sem execução';
    h+=`<div style="margin-bottom:20px">`;
    h+=`<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:8px">`;
    h+=`<div style="display:flex;align-items:center;gap:8px">`;
    h+=`<span style="font-weight:700;color:var(--text);font-size:14px">${esc(func)}</span>`;
    h+=`<span style="padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;background:${statusCor}20;color:${statusCor}">${statusLabel}</span>`;
    h+=`</div>`;
    h+=`<span style="font-size:12px;color:var(--muted)">${dados.total} emenda${dados.total!==1?'s':''} · Empenhado: <strong style="color:#2563eb">${fmtBRL(dados.emp)}</strong> · Pago: <strong style="color:#16a34a">${fmtBRL(dados.pag)}</strong></span>`;
    h+=`</div>`;
    h+=`<div class="table-wrap"><table><thead><tr>
      <th>Nº</th><th>Região</th><th>Ação Orçamentária</th><th>Subfunção</th><th>Localidade</th>
      <th style="text-align:right">Empenhado</th><th style="text-align:right">Liquidado</th><th style="text-align:right">Pago</th>
    </tr></thead><tbody>`;
    dados.itens.forEach(e=>{
      const sc = e.valor_pago>0?'#16a34a':e.valor_liquidado>0?'#d97706':e.valor_empenhado>0?'#2563eb':'#9ca3af';
      const hasMun = e.municipios && e.municipios.length > 0;
      const isMultiplo = hasMun && e.municipios.length > 1;
      const rowId = 'mun_' + (e.id||e.numero||Math.random().toString(36).slice(2)).toString().replace(/[^a-zA-Z0-9_-]/g,'_');
      const localCell = isMultiplo
        ? `<span onclick="toggleMunicipios('${rowId}')" style="cursor:pointer;color:#2563eb;font-weight:600;display:inline-flex;align-items:center;gap:4px">
             <span id="${rowId}_ico">▶</span> múltiplo (${e.municipios.length})
           </span>`
        : hasMun
          ? esc(e.municipios[0].municipio + (e.municipios[0].uf ? '/' + e.municipios[0].uf : ''))
          : esc(e.localidade||'—');
      h+=`<tr>
        <td style="font-weight:600;white-space:nowrap">${esc(e.numero||'—')}</td>
        <td style="font-size:12px;max-width:160px">${esc(e.orgao||'—')}</td>
        <td style="font-size:12px;max-width:160px">${esc(e.acao||e.programa||'—')}</td>
        <td style="font-size:12px">${esc(e.subfuncao||'—')}</td>
        <td style="font-size:12px">${localCell}</td>
        <td style="text-align:right;color:#2563eb">${fmtBRL(e.valor_empenhado)}</td>
        <td style="text-align:right;color:#d97706">${fmtBRL(e.valor_liquidado)}</td>
        <td style="text-align:right;font-weight:600;color:${sc}">${fmtBRL(e.valor_pago)}</td>
      </tr>`;
      if (isMultiplo) {
        h+=`<tr id="${rowId}" style="display:none"><td colspan="8" style="padding:0">`;
        h+=`<div style="background:#f8fafc;border-top:1px solid var(--border);padding:12px 16px">`;
        h+=`<div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Municípios contemplados</div>`;
        h+=`<table style="width:100%;font-size:12px;border-collapse:collapse">`;
        h+=`<thead><tr style="border-bottom:1px solid var(--border)">`;
        h+=`<th style="text-align:left;padding:4px 8px;font-weight:600;color:var(--muted)">Município</th>`;
        h+=`<th style="text-align:left;padding:4px 8px;font-weight:600;color:var(--muted)">UF</th>`;
        h+=`<th style="text-align:right;padding:4px 8px;font-weight:600;color:var(--muted)">Empenhado</th>`;
        h+=`<th style="text-align:right;padding:4px 8px;font-weight:600;color:var(--muted)">Liquidado</th>`;
        h+=`<th style="text-align:right;padding:4px 8px;font-weight:600;color:var(--muted)">Pago</th>`;
        h+=`</tr></thead><tbody>`;
        e.municipios.forEach(m=>{
          const mc = m.pag>0?'#16a34a':m.liq>0?'#d97706':m.emp>0?'#2563eb':'#9ca3af';
          h+=`<tr style="border-bottom:1px solid #f0f0f0">`;
          h+=`<td style="padding:5px 8px;font-weight:500">${esc(m.municipio||'—')}</td>`;
          h+=`<td style="padding:5px 8px;color:var(--muted)">${esc(m.uf||'—')}</td>`;
          h+=`<td style="padding:5px 8px;text-align:right;color:#2563eb">${fmtBRL(m.emp)}</td>`;
          h+=`<td style="padding:5px 8px;text-align:right;color:#d97706">${fmtBRL(m.liq)}</td>`;
          h+=`<td style="padding:5px 8px;text-align:right;font-weight:600;color:${mc}">${fmtBRL(m.pag)}</td>`;
          h+=`</tr>`;
        });
        h+=`</tbody></table></div></td></tr>`;
      }
    });
    h+=`</tbody></table></div></div>`;
  });
  h+=`</div></div>`;
  return h;
}

function switchEmendaAno(parlId, ano) {
  _emendaYear   = parseInt(ano);
  _emendaFuncao = '';
  _emendaOrgao  = '';
  _emendaLocal  = '';
  const p = allParlamentares.find(x=>x.id===parlId)||currentProfile;
  if(p) switchTab('emendas');
}

function filterEmendas(parlId, campo, valor) {
  if(campo==='funcao') _emendaFuncao = valor;
  if(campo==='orgao')  _emendaOrgao  = valor;
  if(campo==='local')  _emendaLocal  = valor;
  const p = allParlamentares.find(x=>x.id===parlId)||currentProfile;
  if(p) switchTab('emendas');
}

function clearEmendaFiltros(parlId) {
  _emendaFuncao = '';
  _emendaOrgao  = '';
  _emendaLocal  = '';
  const p = allParlamentares.find(x=>x.id===parlId)||currentProfile;
  if(p) switchTab('emendas');
}

function toggleMunicipios(rowId) {
  const row = document.getElementById(rowId);
  const ico = document.getElementById(rowId + '_ico');
  if (!row) return;
  const visible = row.style.display !== 'none';
  row.style.display = visible ? 'none' : '';
  if (ico) ico.textContent = visible ? '▶' : '▼';
}

function showNormaGrupo(tipoRaw, ano, isPrimeiro, page) {
  page = Math.max(1, page||1);
  const PER_PAGE = 10;
  const el=document.getElementById('tabContent');
  if(!el||!currentProfile) return;
  const cached=tabDataCache[currentProfile.id]?.normas;
  if(!cached) return;
  Promise.resolve(cached).then(normas=>{
    const filtrado=normas.filter(n=>{
      const info=extractNormaInfo(n);
      return info.tipoRaw===tipoRaw && String(info.ano)===String(ano) && Boolean(n.primeiro_autor)===Boolean(isPrimeiro);
    });
    const totalPages=Math.max(1,Math.ceil(filtrado.length/PER_PAGE));
    page=Math.min(page,totalPages);
    const slice=filtrado.slice((page-1)*PER_PAGE, page*PER_PAGE);
    const sigla=normaTipoNomesRev[tipoRaw]||tipoRaw;
    const nome=normaTipoNomes[sigla]||tipoRaw;
    const autorLabel=isPrimeiro?'Primeiro Autor':'Co-Autor';
    let h=`<button onclick="switchTab('normas')" style="display:inline-flex;align-items:center;gap:6px;margin-bottom:20px;padding:7px 14px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);font-size:13px;font-weight:600;font-family:inherit;cursor:pointer"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Voltar</button>`;
    h+=`<h3 class="section-title" style="margin-bottom:4px">${esc(sigla)} <span style="color:var(--muted);font-weight:400">— ${esc(nome)}</span></h3>`;
    h+=`<div style="font-size:13px;color:var(--muted);margin-bottom:16px">${esc(String(ano))} · ${esc(autorLabel)} · ${filtrado.length.toLocaleString('pt-BR')} norma${filtrado.length!==1?'s':''}</div>`;
    h+='<div class="table-wrap"><table><thead><tr><th>Norma</th><th>1º Autor</th><th>PDF</th></tr></thead><tbody>';
    slice.forEach(n=>{
      const {label}=extractNormaInfo(n);
      const normaId=n.norma||null;
      h+=`<tr>
        <td>${normaId?`<a href="javascript:void(0)" onclick="openNorma(${normaId})" style="color:var(--accent);font-weight:500">${esc(label)}</a>`:esc(label)}</td>
        <td><span class="td-titular ${n.primeiro_autor?'yes':'no'}">${n.primeiro_autor?'Sim':'Não'}</span></td>
        <td>${normaId?`<button onclick="openNorma(${normaId})" class="btn-ver">Ver</button>`:'—'}</td>
      </tr>`;
    });
    h+='</tbody></table></div>';
    if(totalPages>1){
      h+=`<div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap">`;
      h+=`<button onclick="showNormaGrupo('${tipoRaw}','${String(ano)}',${isPrimeiro},${page-1})" ${page<=1?'disabled':''} style="padding:5px 12px;border-radius:6px;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);font-size:13px;cursor:pointer;font-family:inherit">‹ Anterior</button>`;
      const start=Math.max(1,page-2), end=Math.min(totalPages,page+2);
      if(start>1) h+=`<button onclick="showNormaGrupo('${tipoRaw}','${String(ano)}',${isPrimeiro},1)" style="padding:5px 10px;border-radius:6px;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);font-size:13px;cursor:pointer;font-family:inherit">1</button>`;
      if(start>2) h+=`<span style="color:var(--muted);font-size:13px">…</span>`;
      for(let i=start;i<=end;i++){
        const active=i===page;
        h+=`<button onclick="showNormaGrupo('${tipoRaw}','${String(ano)}',${isPrimeiro},${i})" style="padding:5px 10px;border-radius:6px;border:1.5px solid ${active?'var(--accent)':'var(--border)'};background:${active?'var(--accent)':'var(--surface)'};color:${active?'#fff':'var(--muted)'};font-size:13px;font-weight:${active?700:400};cursor:pointer;font-family:inherit">${i}</button>`;
      }
      if(end<totalPages-1) h+=`<span style="color:var(--muted);font-size:13px">…</span>`;
      if(end<totalPages) h+=`<button onclick="showNormaGrupo('${tipoRaw}','${String(ano)}',${isPrimeiro},${totalPages})" style="padding:5px 10px;border-radius:6px;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);font-size:13px;cursor:pointer;font-family:inherit">${totalPages}</button>`;
      h+=`<button onclick="showNormaGrupo('${tipoRaw}','${String(ano)}',${isPrimeiro},${page+1})" ${page>=totalPages?'disabled':''} style="padding:5px 12px;border-radius:6px;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);font-size:13px;cursor:pointer;font-family:inherit">Próximo ›</button>`;
      h+=`<span style="font-size:12px;color:var(--muted);margin-left:4px">Pág. ${page}/${totalPages}</span>`;
      h+='</div>';
    }
    el.innerHTML=h;
  });
}

// ── Norma Detail ──
async function openNorma(normaId) {
  const main=document.getElementById("mainContent");
  main.innerHTML='<div class="loader"><div class="spinner"></div><span style="color:var(--muted);font-size:14px">Carregando norma...</span></div>';
  window.scrollTo({top:0,behavior:"smooth"});
  try{
    const n=await fetchWithRetry(proxyUrl(`/norma/normajuridica/${normaId}/`));
    if(!n||!n.id) throw new Error("Norma não encontrada");

    let h='<div style="padding-top:28px;padding-bottom:60px">';
    h+='<button class="profile-back" onclick="closeNorma()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Voltar</button>';
    h+='<div class="materia-card">';
    const normaTitle=(()=>{const raw=stripAutoria(n.__str__||'');const d=raw.indexOf(' - ');return d>=0?raw.slice(d+3):raw;})();
    h+=`<h1 class="materia-title">${esc(normaTitle||n.__str__||'Norma #'+n.id)}</h1>`;

    if(n.ementa){
      h+=`<div style="margin:16px 0"><div class="materia-ementa">${esc(n.ementa)}</div></div>`;
    }

    const pdfUrl=n.texto_integral?(n.texto_integral.startsWith('http')?n.texto_integral:API_BASE+n.texto_integral):null;
    if(pdfUrl){
      h+=`<div style="margin:16px 0"><a href="${esc(pdfUrl)}" target="_blank" class="btn-pdf"><i class="ph ph-file-pdf"></i> Abrir documento PDF</a></div>`;
    }

    h+='<div class="materia-grid" style="margin-top:20px">';
    const tipoStr=n.tipo?(typeof n.tipo==='object'?(n.tipo.__str__||n.tipo.descricao||String(n.tipo.id||n.tipo)):String(n.tipo)):null;
    if(tipoStr)                  h+=`<div class="materia-field"><span class="materia-label">Tipo</span><div class="materia-value">${esc(tipoStr)}</div></div>`;
    if(n.numero)                 h+=`<div class="materia-field"><span class="materia-label">Número</span><div class="materia-value">${n.numero}</div></div>`;
    if(n.ano)                    h+=`<div class="materia-field"><span class="materia-label">Ano</span><div class="materia-value">${n.ano}</div></div>`;
    if(n.data)                   h+=`<div class="materia-field"><span class="materia-label">Data</span><div class="materia-value">${fmtDate(n.data)}</div></div>`;
    if(n.data_publicacao_diario) h+=`<div class="materia-field"><span class="materia-label">Publicação</span><div class="materia-value">${fmtDate(n.data_publicacao_diario)}</div></div>`;
    if(n.esfera_federacao)       h+=`<div class="materia-field"><span class="materia-label">Esfera</span><div class="materia-value">${esc(n.esfera_federacao)}</div></div>`;
    h+='</div></div></div>';
    main.innerHTML=h;
  }catch(e){
    console.error(e);
    main.innerHTML=`<div class="empty"><p style="color:var(--red);font-weight:600">Erro ao carregar norma</p><p style="margin-top:8px;color:var(--muted)">${esc(e.message)}</p><button class="profile-back" onclick="closeNorma()" style="margin-top:16px">← Voltar</button></div>`;
  }
}

function closeNorma() {
  if(currentProfile){
    const main=document.getElementById("mainContent");
    renderProfileShell(currentProfile).then(html=>{
      main.innerHTML=html;
      switchTab('normas');
    });
  }else{backToList()}
}

// ── Tab: Filiações ──
async function renderTabFiliacoes(p) {
  const fil=await getCached(p.id,'_filiacoes',()=>fetchAllPages(`/parlamentares/filiacao/?parlamentar=${p.id}`));
  fil.sort((a,b)=>(b.data||"").localeCompare(a.data||""));
  if(!fil.length) return semDados('Nenhuma filiação encontrada');

  let h=`<h3 class="section-title">Filiações Partidárias (${fil.length})</h3>`;
  const thead='<tr><th>Partido</th><th>Data Filiação</th><th>Data Desfiliação</th><th>Status</th></tr>';
  h+=paginateTable(fil,10,tablePages['pg-filiacoes']||1,f=>{
    const sigla=typeof f.partido==='string'?f.partido:(allPartidosSigla[f.partido]||"?");
    const nome=typeof f.partido==='string'?f.partido:(allPartidos[f.partido]||"");
    const isActive=!f.data_desfiliacao,pc=partyColor(sigla);
    return `<tr>
      <td><span class="card-party" style="background:${pc[0]};color:${pc[1]};margin-right:8px">${esc(sigla)}</span><span style="color:#111827">${esc(nome)}</span></td>
      <td style="color:#111827">${fmtDate(f.data)}</td>
      <td style="color:#111827">${fmtDate(f.data_desfiliacao)}</td>
      <td><span class="td-titular ${isActive?'yes':'no'}">${isActive?'Atual':'Encerrada'}</span></td>
    </tr>`;
  },thead,'pg-filiacoes');
  return h;
}

// ── Tab: Comissões ──
async function renderTabComissoes(p) {
  const [parts, capC]=await Promise.all([
    getCached(p.id,'comissoes',()=>fetchAllPages(`/comissoes/participacao/?parlamentar=${p.id}`)),
    getSourceCap(),
  ]);
  parts.sort((a,b)=>(b.data_designacao||"").localeCompare(a.data_designacao||""));
  if(!parts.length) return capC.comissoes ? semDados('Nenhuma participação em comissão encontrada para este parlamentar') : semModulo('Esta fonte não utiliza o módulo de Comissões');

  let h=`<h3 class="section-title">Participações em Comissão (${parts.length})</h3>`;
  const thead='<tr><th>Comissão</th><th>Cargo</th><th>Período de participação</th></tr>';
  h+=paginateTable(parts,10,tablePages['pg-comissoes']||1,c=>{
    const str=c.__str__||'';
    let comissaoNome='', cargo='';
    if(str.includes(' : ')){
      const p=str.split(' : ');
      cargo        = p[0].trim();
      comissaoNome = p.slice(1).join(' : ').trim();
    } else {
      const sepIdx=str.lastIndexOf(' - ');
      comissaoNome = sepIdx>0 ? str.slice(0,sepIdx).trim() : str;
      cargo        = sepIdx>0 ? str.slice(sepIdx+3).trim() : '';
    }
    if(!comissaoNome) comissaoNome=str;
    const inicio  = fmtDate(c.data_inicio_participacao||c.data_designacao||'');
    const fim     = fmtDate(c.data_fim_participacao||c.data_desligamento||'');
    const periodo = inicio&&fim ? `${inicio} a ${fim}` : inicio||fim||'—';
    const cId     = c.comissao_id||'';
    const clickAttr = cId ? `onclick="openComissao(${cId})" style="color:var(--accent);cursor:pointer;text-decoration:underline;text-underline-offset:2px"` : `style="color:var(--accent)"`;
    return `<tr>
      <td style="font-weight:500"><span ${clickAttr} title="${esc(comissaoNome)}">${esc(comissaoNome)}</span></td>
      <td style="color:#374151">${cargo?esc(cargo):'—'}</td>
      <td style="color:#6b7280;white-space:nowrap">${periodo}</td>
    </tr>`;
  },thead,'pg-comissoes');
  return h;
}

function showModal(title, bodyHtml, {printable=false}={}) {
  document.getElementById('app-modal')?.remove();
  const el = document.createElement('div');
  el.id = 'app-modal';
  el.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px';
  const printBtn = printable
    ? `<button id="modal-print-btn" onclick="printModal()" style="border:none;background:var(--accent);color:#fff;cursor:pointer;font-size:.8rem;font-weight:600;padding:5px 14px;border-radius:6px;margin-right:8px;display:flex;align-items:center;gap:5px"><i class="ph ph-printer"></i> Imprimir</button>`
    : '';
  el.innerHTML=`<div style="background:#fff;border-radius:14px;width:100%;max-width:680px;max-height:88vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.25)">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 24px;border-bottom:1px solid #e5e7eb;position:sticky;top:0;background:#fff;z-index:1">
      <h3 style="margin:0;font-size:1rem;font-weight:700;color:#111827">${esc(title)}</h3>
      <div style="display:flex;align-items:center">${printBtn}<button onclick="document.getElementById('app-modal').remove()" style="border:none;background:none;cursor:pointer;font-size:1.4rem;color:#6b7280;line-height:1">×</button></div>
    </div>
    <div id="app-modal-body">${bodyHtml}</div>
  </div>`;
  el.addEventListener('click', e => { if(e.target===el) el.remove(); });
  document.body.appendChild(el);
}

function printModal() {
  const body = document.getElementById('app-modal-body');
  const title = document.querySelector('#app-modal h3')?.textContent || '';
  if (!body) return;
  const w = window.open('', '_blank', 'width=800,height=700');
  w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>${title}</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;font-size:13px;color:#111;padding:24px}
    h1{font-size:16px;font-weight:700;margin-bottom:4px}
    .badge{display:inline-block;font-size:11px;font-weight:600;color:#2563eb;background:#dbeafe;padding:2px 10px;border-radius:20px;margin-bottom:16px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 24px;margin-bottom:20px}
    .field label{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em}
    .field p{font-size:13px;color:#111;margin-top:2px}
    .finalidade{margin-bottom:16px;padding:12px;background:#f9fafb;border-left:3px solid #2563eb;border-radius:4px;font-size:12px;line-height:1.6;color:#374151}
    .sec-title{font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin:16px 0 8px;border-top:1px solid #e5e7eb;padding-top:12px}
    table{width:100%;border-collapse:collapse}
    table th{font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;text-align:left;padding:4px 8px;border-bottom:1px solid #e5e7eb}
    table td{font-size:12px;padding:5px 8px;border-bottom:1px solid #f3f4f6}
    .tag-t{display:inline-block;font-size:10px;font-weight:600;background:#dcfce7;color:#166534;padding:1px 6px;border-radius:4px}
    .tag-s{display:inline-block;font-size:10px;font-weight:600;background:#fef9c3;color:#854d0e;padding:1px 6px;border-radius:4px}
    @media print{body{padding:12px}}
  </style></head><body>`);
  w.document.write(`<h1>${title}</h1>`);
  w.document.write(body.innerHTML);
  w.document.write('</body></html>');
  w.document.close();
  w.focus();
  setTimeout(()=>{ w.print(); }, 400);
}

async function openComissao(comissaoId) {
  showModal('Carregando…','<div style="padding:32px;text-align:center"><i class="ph ph-spinner" style="font-size:2rem;animation:spin 1s linear infinite"></i></div>');
  try {
    const [rC, rM] = await Promise.all([
      fetch(proxyUrl(`/comissoes/comissao/${comissaoId}/`)),
      fetch(proxyUrl(`/comissoes/membros/${comissaoId}/`)),
    ]);
    const c = await rC.json();
    const membrosData = await rM.json();
    if(!c||!c.nome){ showModal('Comissão','<p style="padding:24px">Detalhes não disponíveis.</p>'); return; }

    const membros = membrosData?.results ?? [];
    const ativa = c.ativo??c.ativa??c.comissao_ativa;
    const tipoMap={1:'Comissão Permanente',2:'Comissão Parlamentar de Inquérito',3:'Comissão Temporária',4:'Comissão Mista',5:'Comissão Especial'};
    const tipoNome=c.tipo?.nome||(typeof c.tipo==='number'||typeof c.tipo==='string'?tipoMap[+c.tipo]||'':'');

    const field=(l,v)=>v!=null&&v!==''?`<div class="field"><span style="font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em">${l}</span><div style="font-size:.92rem;color:#111827;margin-top:2px">${v}</div></div>`:'';
    const secTitle=(t)=>`<div style="font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin:20px 0 10px;padding-top:16px;border-top:1px solid #f3f4f6">${t}</div>`;

    let h=`<div style="padding:22px 26px">`;

    // Cabeçalho
    h+=`<div style="margin-bottom:18px">`;
    h+=`<h2 style="font-size:1.15rem;font-weight:700;color:#111827;margin:0 0 6px">${esc(c.nome)}</h2>`;
    if(c.sigla) h+=`<span style="font-size:.82rem;font-weight:600;color:var(--accent);background:var(--accent-light);padding:2px 12px;border-radius:20px">${esc(c.sigla)}</span>`;
    if(tipoNome) h+=`<span style="font-size:.78rem;color:#6b7280;margin-left:8px">${esc(tipoNome)}</span>`;
    h+=`</div>`;

    // Finalidade
    if(c.finalidade && c.finalidade.trim()) {
      h+=`<div style="background:#f8faff;border-left:3px solid var(--accent);border-radius:4px;padding:10px 14px;margin-bottom:18px;font-size:.88rem;color:#374151;line-height:1.6">${esc(c.finalidade.trim())}</div>`;
    }

    // Campos em grid
    h+=`<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 24px">`;
    h+=field('Status', ativa!=null?(ativa?'<span style="color:#16a34a;font-weight:600">Ativa</span>':'<span style="color:#dc2626;font-weight:600">Encerrada</span>'):'');
    h+=field('Unidade Deliberativa', c.unidade_deliberativa!=null?(c.unidade_deliberativa?'Sim':'Não'):'');
    h+=field('Data de Criação', fmtDate(c.data_criacao||''));
    h+=field('Data de Extinção', fmtDate(c.data_extincao||c.data_fim_comissao||''));
    h+=field('Secretário(a)', c.secretario||'');
    h+=field('E-mail', c.email?`<a href="mailto:${esc(c.email)}" style="color:var(--accent)">${esc(c.email)}</a>`:'');
    h+=field('Local de Reunião', c.local_reuniao||'');
    h+=field('Agenda de Reunião', c.agenda_reuniao||'');
    h+=field('Tel. Sala Reunião', c.telefone_reuniao||c.tel_reuniao||'');
    h+=field('Endereço Secretaria', c.endereco_secretaria||'');
    h+=field('Tel. Secretaria', c.telefone_secretaria||c.tel_secretaria||'');
    if(c.fax_secretaria) h+=field('Fax Secretaria', c.fax_secretaria);
    // Campos de comissão temporária
    if(c.apelido_temp) h+=field('Apelido (temp.)', c.apelido_temp);
    if(c.data_instalacao_temp) h+=field('Instalação (temp.)', fmtDate(c.data_instalacao_temp));
    if(c.data_final_prevista_temp) h+=field('Prazo Previsto (temp.)', fmtDate(c.data_final_prevista_temp));
    if(c.data_prorrogada_temp) h+=field('Prazo Prorrogado (temp.)', fmtDate(c.data_prorrogada_temp));
    h+=`</div>`;

    // Membros
    if(membros.length) {
      h+=secTitle(`Membros — ${membros.length} parlamentar${membros.length!==1?'es':''}`);
      h+=`<table style="width:100%;border-collapse:collapse;font-size:.88rem">`;
      h+=`<thead><tr>`;
      h+=`<th style="text-align:left;padding:6px 8px;font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;border-bottom:2px solid #e5e7eb">Parlamentar</th>`;
      h+=`<th style="text-align:left;padding:6px 8px;font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;border-bottom:2px solid #e5e7eb">Participação</th>`;
      h+=`<th style="text-align:left;padding:6px 8px;font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;border-bottom:2px solid #e5e7eb">Desde</th>`;
      h+=`</tr></thead><tbody>`;
      membros.forEach(m=>{
        const isSup = m.cargo==='Suplente';
        const isTit = m.cargo==='Titular';
        const tagColor = isSup ? 'background:#fef9c3;color:#854d0e' : 'background:#dcfce7;color:#166534';
        const tagLabel = isSup ? 'Suplente' : (isTit ? 'Titular' : m.cargo||'Titular');
        const tag=`<span style="font-size:.7rem;font-weight:600;${tagColor};padding:1px 7px;border-radius:4px">${esc(tagLabel)}</span>`;
        const cargoExtra = !isSup&&!isTit&&m.cargo ? '' : ''; // cargo já está na tag
        h+=`<tr style="border-bottom:1px solid #f3f4f6">`;
        h+=`<td style="padding:7px 8px;font-weight:500">${esc(m.nome)}</td>`;
        h+=`<td style="padding:7px 8px">${tag}</td>`;
        h+=`<td style="padding:7px 8px;color:#6b7280">${fmtDate(m.data_inicio||'')}</td>`;
        h+=`</tr>`;
      });
      h+=`</tbody></table>`;
    }

    h+=`</div>`;
    showModal(`${c.sigla||''} — ${c.nome}`, h, {printable:true});
  } catch(e) {
    showModal('Erro','<p style="padding:24px">Não foi possível carregar os detalhes.</p>');
  }
}

// ── Tab: Relatorias ──
let _relatoriasList = [];

async function openRelatoria(idx) {
  const r = _relatoriasList[idx];
  if (!r) return;
  const title = r.__str__ || ('Relatoria #' + (r.materia||''));
  const comissaoInfo = r.comissao ? (typeof r.comissao==='object' ? (r.comissao.__str__||r.comissao.nome||'—') : String(r.comissao)) : '—';

  // Separar matéria e situação do __str__ (formato: "PL nº X/ANO - Situação - Data")
  const partes = title.split(' - ');
  const materiaId = partes[0]?.trim() || title;
  const situacao = partes.length > 1 ? partes.slice(1, -1).join(' - ').trim() : '';

  showModal('Carregando…','<div style="padding:32px;text-align:center"><i class="ph ph-spinner" style="font-size:2rem;animation:spin 1s linear infinite"></i></div>');
  let ementa = '', tipoStr = '', numStr = '', anoStr = '', statusTram = '';

  if (r.materia) {
    try {
      const m = await fetchWithRetry(proxyUrl(`/materia/materialegislativa/${r.materia}/`));
      if (m && m.id) {
        ementa = m.ementa || '';
        tipoStr = m.tipo?.sigla || m.tipo?.descricao || (typeof m.tipo==='string'?m.tipo:'') || '';
        numStr = m.numero || '';
        anoStr = m.ano ? String(m.ano) : '';
        statusTram = m.em_tramitacao != null ? (m.em_tramitacao ? 'Em tramitação' : 'Tramitação encerrada') : '';
      }
    } catch(e) {}
  }

  const field=(l,v)=>v?`<div><span style="font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em">${l}</span><div style="font-size:.92rem;color:#111827;margin-top:2px">${v}</div></div>`:'';

  let h = `<div style="padding:22px 26px">`;

  // Identificação
  h += `<div style="margin-bottom:16px">`;
  h += `<h2 style="font-size:1.1rem;font-weight:700;color:#111827;margin:0 0 6px">${esc(materiaId)}</h2>`;
  if (tipoStr) h += `<span style="font-size:.8rem;font-weight:600;color:var(--accent);background:var(--accent-light);padding:2px 10px;border-radius:20px;margin-right:6px">${esc(tipoStr)}</span>`;
  if (statusTram) {
    const stColor = statusTram.includes('Em') ? 'color:#16a34a;background:#dcfce7' : 'color:#dc2626;background:#fee2e2';
    h += `<span style="font-size:.78rem;font-weight:600;${stColor};padding:2px 10px;border-radius:20px">${esc(statusTram)}</span>`;
  }
  h += `</div>`;

  if (ementa) {
    h += `<div style="background:#f8faff;border-left:3px solid var(--accent);border-radius:4px;padding:10px 14px;margin-bottom:18px;font-size:.88rem;color:#374151;line-height:1.6">${esc(ementa)}</div>`;
  }

  h += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 24px">`;
  if (tipoStr) h += field('Tipo', tipoStr);
  if (numStr)  h += field('Número', numStr);
  if (anoStr)  h += field('Ano', anoStr);
  h += field('Comissão', comissaoInfo);
  h += field('Designação', fmtDate(r.data_designacao_relator||''));
  h += field('Destituição', r.data_destituicao_relator ? fmtDate(r.data_destituicao_relator) : '<span style="color:#16a34a;font-weight:600">Em exercício</span>');
  if (situacao) h += field('Situação da Relatoria', situacao);
  h += `</div>`;

  h += `</div>`;
  showModal(`Relatoria — ${materiaId}`, h);
}


async function renderTabRelatorias(p) {
  const [rels, capR]=await Promise.all([
    getCached(p.id,'relatorias',()=>fetchAllPages(`/materia/relatoria/?parlamentar=${p.id}`)),
    getSourceCap(),
  ]);
  rels.sort((a,b)=>(b.data_designacao_relator||"").localeCompare(a.data_designacao_relator||""));
  if(!rels.length) return capR.relatorias ? semDados('Nenhuma relatoria encontrada para este parlamentar') : semModulo('Esta fonte não utiliza Relatorias');
  _relatoriasList = rels;

  let h=`<h3 class="section-title">Relatorias (${rels.length})</h3>`;

  const thead='<tr><th>Matéria</th><th>Comissão</th><th>Designação</th><th>Destituição</th></tr>';
  h+=`<div id="tab-relatorias">`;
  h+=paginateTable(rels,10,tablePages['pg-relatorias']||1,(r,idx)=>{
    const title=r.__str__||('Relatoria #'+r.materia);
    // Pega só a identificação da matéria (antes do primeiro " - ")
    const materiaLabel = title.split(' - ')[0]?.trim() || title;
    const comissaoInfo=r.comissao?(typeof r.comissao==='object'?(r.comissao.__str__||r.comissao.nome||'—'):'—'):'—';
    const destStr = r.data_destituicao_relator ? fmtDate(r.data_destituicao_relator) : '<span style="color:#16a34a;font-size:.8em">Em exercício</span>';
    return `<tr style="cursor:pointer" onclick="openRelatoria(${idx})" title="Clique para ver detalhes">
      <td style="font-weight:500;color:var(--accent)">${esc(materiaLabel)}</td>
      <td style="color:#111827">${esc(comissaoInfo)}</td>
      <td style="color:#111827">${fmtDate(r.data_designacao_relator)}</td>
      <td>${destStr}</td>
    </tr>`;
  },thead,'pg-relatorias');
  h+=`</div>`;
  return h;
}

// ── Tab: Frentes ──
async function renderTabFrentes(p) {
  const [frentes, capF]=await Promise.all([
    getCached(p.id,'frentes',()=>fetchAllPages(`/parlamentares/frenteparlamentar/?parlamentar=${p.id}`)),
    getSourceCap(),
  ]);
  if(!frentes.length) return capF.frentes ? semDados('Nenhuma participação em frente parlamentar encontrada para este parlamentar') : semModulo('Esta fonte não utiliza Frentes Parlamentares');

  const [cargos, allFrentes]=await Promise.all([
    getCached('global','frentecargos',()=>fetchAllPages('/parlamentares/frentecargo/')),
    getCached('global','allfrentes',  ()=>fetchAllPages('/parlamentares/frente/')),
  ]);
  const cargoMap={};cargos.forEach(c=>cargoMap[c.id]=c.nome_cargo);
  const frenteMap={};allFrentes.forEach(f=>frenteMap[f.id]=f);

  let h=`<h3 class="section-title">Frentes Parlamentares</h3>`;
  h+=`<div style="margin-bottom:16px;font-size:14px;color:var(--muted)">Total: <strong style="color:#111827">${frentes.length}</strong></div>`;
  h+='<div class="table-wrap"><table><thead><tr><th>Frente</th><th>Cargo</th><th>Data de Entrada</th><th>Data de Saída</th></tr></thead><tbody>';
  frentes.forEach(f=>{
    const frente=frenteMap[f.frente];
    const nome=frente?frente.nome:(f.__str__||'Frente #'+f.frente);
    const cargo=cargoMap[f.cargo]||'—';
    h+=`<tr>
      <td style="font-weight:600">${esc(nome)}</td>
      <td style="color:#111827">${esc(cargo)}</td>
      <td style="color:#111827">${fmtDate(f.data_entrada)}</td>
      <td style="color:#111827">${fmtDate(f.data_saida)}</td>
    </tr>`;
  });
  h+='</tbody></table></div>';
  return h;
}

// ══════════════════════════════════════════════════════
// TAB: AGENTE IA
// ══════════════════════════════════════════════════════
let agenteBusy = false;
let agenteContext = null;
let agenteHistory = [];
let agenteHistoryParlId = null;
const MAX_AGENTE_TURNS = 20;

function _histUrl(parlId) {
  return (APP_CONFIG.basePath||'')+'/api/agente-historico?contexto=parlamentar&contexto_id='+encodeURIComponent(parlId);
}
async function fetchAgenteContextoParlamentar(parlId) {
  const qs = new URLSearchParams({source: APP_CONFIG.source || '', sapl_id: parlId});
  const res = await fetch((APP_CONFIG.basePath||'') + '/api/agente-contexto?' + qs.toString());
  const data = await res.json();
  if(!res.ok || data.error) throw new Error(data.error || 'Erro ao carregar contexto do agente');
  return data;
}
function agenteHistorySave() {
  if(!agenteHistoryParlId) return;
  fetch(_histUrl(agenteHistoryParlId), {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({historico: agenteHistory})
  }).catch(()=>{});
}
async function agenteHistoryClear() {
  agenteHistory = [];
  if(agenteHistoryParlId) {
    await fetch(_histUrl(agenteHistoryParlId), {method:'DELETE'}).catch(()=>{});
  }
  const area = document.getElementById('agenteMsgs');
  if(area) area.innerHTML = '';
  agenteAddMsg('ia','<p>Conversa reiniciada. Como posso ajudar?</p>');
}
async function agenteHistoryLoad(parlId) {
  try {
    const res  = await fetch(_histUrl(parlId));
    const data = await res.json();
    const hist = data.historico;
    if(!Array.isArray(hist)||!hist.length) return;
    agenteHistory = hist;
    hist.forEach(msg => {
      if(msg.role==='user')           agenteAddMsg('user','<p>'+esc(msg.content)+'</p>');
      else if(msg.role==='assistant') agenteAddMsg('ia',agenteFmt(msg.content));
    });
  } catch(e) {}
}

async function renderTabAgente(p) {
  const isCamaraAg = APP_CONFIG.source==='camara_federal';
  let allMat = [], allNorm = [], comissoes = [], filiacoes = [], mandatos = [], frentes = [], emendas = [], relatorias = [];
  try {
    const ctxData = await getCached(p.id, 'agente_contexto_all', () => fetchAgenteContextoParlamentar(p.id));
    allMat    = ctxData.materias   || [];
    allNorm   = ctxData.normas     || [];
    comissoes = ctxData.comissoes  || [];
    filiacoes = ctxData.filiacoes  || [];
    mandatos  = ctxData.mandatos   || [];
    frentes   = ctxData.frentes    || [];
    emendas   = ctxData.emendas    || [];
    relatorias= ctxData.relatorias || [];
  } catch(e) {
    const autorData = await getAutorData(p);
    [allMat, allNorm, comissoes, filiacoes, mandatos, frentes, emendas] = await Promise.all([
      autorData ? getCached(p.id,'all_materias',()=>fetchAllPages(`/materia/autoria/?autor=${autorData.id}&o=-id`)) : Promise.resolve([]),
      autorData ? getCached(p.id,'normas',()=>fetchAllPages(`/norma/autorianorma/?autor=${autorData.id}`)) : Promise.resolve([]),
      getCached(p.id,'comissoes_ag',()=>fetchAllPages(`/comissoes/participacao/?parlamentar=${p.id}`)).catch(()=>[]),
      getCached(p.id,'filiacoes_ag',()=>fetchAllPages(`/parlamentares/filiacao/?parlamentar=${p.id}`)).catch(()=>[]),
      getCached(p.id,'mandatos_ag', ()=>fetchAllPages(`/parlamentares/mandato/?parlamentar=${p.id}`)).catch(()=>[]),
      getCached(p.id,'frentes_ag',  ()=>fetchAllPages(`/parlamentares/frenteparlamentar/?parlamentar=${p.id}`)).catch(()=>[]),
      isCamaraAg ? getCached(p.id,'emendas_todas',()=>fetchAllPages(`/emendas/parlamentar/?parlamentar=${p.id}`)).catch(()=>[]) : Promise.resolve([]),
    ]);
  }

  const nomeParlamentar = p.nome_parlamentar||p.nome_completo||'';
  const matLabel  = isCamaraAg ? 'proposições' : 'matérias';
  const normLabel = isCamaraAg ? 'normas sancionadas (proposições que viraram lei)' : 'normas jurídicas';

  const tiposMat  = Object.entries(groupByTipo(allMat)).sort((a,b)=>b[1]-a[1]);
  const tiposNorm = Object.entries(groupByTipo(allNorm)).sort((a,b)=>b[1]-a[1]);
  const anosMatList  = Object.entries(groupByYear(allMat)).sort((a,b)=>b[0].localeCompare(a[0]));
  const anosNormList = Object.entries(groupByYear(allNorm)).sort((a,b)=>b[0].localeCompare(a[0]));

  const MAT_LIMIT  = 1500;
  const NORM_LIMIT = 500;
  const STR_MAX    = 120;

  let ctx = `# DADOS DO PARLAMENTAR\n`;
  ctx += `Nome: ${nomeParlamentar}\n`;
  ctx += `Partido: ${p.partido?.sigla||p.partido_sigla||'—'} | UF: ${p.uf||'—'} | Situação: ${p.ativo?'Ativo':'Inativo'}\n`;
  if(p.profissao) ctx += `Profissão: ${p.profissao}\n`;
  if(p.escolaridade) ctx += `Escolaridade: ${p.escolaridade}\n`;
  ctx += '\n';

  // Mandatos
  if(mandatos.length) {
    ctx += `## Mandatos (${mandatos.length})\n`;
    mandatos.forEach(m=>{ctx+=`  - Legislatura ${m.legislatura||m.legislatura_id||'?'}${m.titular?' (Titular)':' (Suplente)'}\n`;});
    ctx += '\n';
  }

  // Filiações
  if(filiacoes.length) {
    ctx += `## Filiações Partidárias (${filiacoes.length})\n`;
    filiacoes.slice(0,10).forEach(f=>{
      const sig=(f.__str__||'').replace(/^Filiação.*?:\s*/,'').trim()||f.partido?.sigla||'';
      if(sig) ctx+=`  - ${sig}\n`;
    });
    ctx += '\n';
  }

  // Comissões
  if(comissoes.length) {
    ctx += `## Comissões (${comissoes.length})\n`;
    comissoes.slice(0,20).forEach(c=>{
      const s=(c.__str__||c.nome||'').trim();
      if(s) ctx+=`  - ${s.slice(0,80)}\n`;
    });
    if(comissoes.length>20) ctx+=`  ... e mais ${comissoes.length-20}\n`;
    ctx += '\n';
  }

  // Frentes
  if(frentes.length) {
    ctx += `## Frentes Parlamentares (${frentes.length})\n`;
    frentes.slice(0,15).forEach(f=>{const t=(f.titulo||f.__str__||'').trim();if(t) ctx+=`  - ${t.slice(0,80)}\n`;});
    if(frentes.length>15) ctx+=`  ... e mais ${frentes.length-15}\n`;
    ctx += '\n';
  }

  if(relatorias.length) {
    ctx += `## Relatorias (${relatorias.length})\n`;
    relatorias.slice(0,25).forEach(r=>{const t=(r.__str__||'').trim();if(t) ctx+=`  - ${t.slice(0,100)}\n`;});
    if(relatorias.length>25) ctx+=`  ... e mais ${relatorias.length-25}\n`;
    ctx += '\n';
  }

  // Emendas (só Câmara Federal)
  if(isCamaraAg && emendas.length) {
    const fmtM = v=>v>0?'R$ '+v.toLocaleString('pt-BR',{minimumFractionDigits:2}):'—';
    const totDot = emendas.reduce((s,e)=>s+(e.valor_dotacao||0),0);
    const totPag = emendas.reduce((s,e)=>s+(e.valor_pago||0),0);
    const anosEmendas = [...new Set(emendas.map(e=>e.ano).filter(Boolean))].sort((a,b)=>b-a);
    ctx += `## Emendas Parlamentares - todos os anos (${emendas.length})\n`;
    if(anosEmendas.length) ctx += `Anos disponíveis: ${anosEmendas.join(', ')}\n`;
    ctx += `Dotação total: ${fmtM(totDot)} | Valor pago: ${fmtM(totPag)}\n`;
    const porFunc={};
    emendas.forEach(e=>{const f=e.funcao||'Outros';porFunc[f]=(porFunc[f]||0)+(e.valor_dotacao||0);});
    const funcs=Object.entries(porFunc).sort((a,b)=>b[1]-a[1]);
    ctx += `Distribuição por função:\n`;
    funcs.forEach(([f,v])=>{ctx+=`  - ${f}: ${fmtM(v)}\n`;});
    ctx += '\n';
    ctx += `Emendas (ano, destino, função, valor dotado):\n`;
    emendas.slice(0,50).forEach(e=>{
      const destCtx = e.municipios && e.municipios.length
        ? 'múltiplo (' + e.municipios.slice(0,5).map(m=>`${m.municipio}/${m.uf}: ${fmtM(m.emp)}`).join(', ') + (e.municipios.length>5?`, +${e.municipios.length-5}`:'') + ')'
        : (e.localidade||'—');
      ctx+=`  - ${e.ano||'—'} | Nº ${e.numero||'?'} | ${e.funcao||'—'} / ${e.subfuncao||'—'} | Destino: ${destCtx} | Dotação: ${fmtM(e.valor_dotacao)} | Pago: ${fmtM(e.valor_pago)}\n`;
    });
    if(emendas.length>50) ctx+=`  ... e mais ${emendas.length-50} emendas\n`;
    ctx += '\n';
  }

  // Proposições / Matérias
  ctx += `## ${isCamaraAg?'Proposições Legislativas':'Matérias Legislativas'} (${allMat.length} total)\n`;
  if(tiposMat.length) {
    ctx += `Por tipo:\n${tiposMat.map(([t,n])=>`  ${t}: ${n}`).join('\n')}\n`;
  }
  if(anosMatList.length) {
    ctx += `Por ano:\n${anosMatList.map(([a,n])=>`  ${a}: ${n}`).join('\n')}\n`;
  }
  ctx += '\n';
  const listMat = allMat.slice(0,MAT_LIMIT).map(m=>{const s=stripAutoria(m.__str__||'');return s.length>STR_MAX?s.slice(0,STR_MAX)+'…':s;}).filter(Boolean);
  if(listMat.length) {
    ctx += `Lista ${isCamaraAg?'de proposições':'de matérias'} (${listMat.length}${allMat.length>MAT_LIMIT?' de '+allMat.length:''}, mais recentes primeiro):\n`;
    ctx += listMat.map(m=>`  - ${m}`).join('\n') + '\n\n';
  }

  // Normas / Sancionadas
  if(allNorm.length) {
    ctx += `## ${isCamaraAg?'Normas Sancionadas':'Normas Jurídicas'} (${allNorm.length} total)\n`;
    if(tiposNorm.length) ctx += `Por tipo:\n${tiposNorm.map(([t,n])=>`  ${t}: ${n}`).join('\n')}\n`;
    if(anosNormList.length) ctx += `Por ano:\n${anosNormList.map(([a,n])=>`  ${a}: ${n}`).join('\n')}\n`;
    ctx += '\n';
    const listNorm = allNorm.slice(0,NORM_LIMIT).map(n=>{const s=stripAutoria(n.__str__||'');return s.length>STR_MAX?s.slice(0,STR_MAX)+'…':s;}).filter(Boolean);
    if(listNorm.length) {
      ctx += `Lista de ${normLabel} (${listNorm.length}${allNorm.length>NORM_LIMIT?' de '+allNorm.length:''}):\n`;
      ctx += listNorm.map(n=>`  - ${n}`).join('\n') + '\n';
    }
  }

  agenteContext = ctx;
  agenteHistory = [];
  agenteHistoryParlId = p.id;
  agenteBusy = false;

  const ctxSummary = [
    allMat.length ? `<strong>${allMat.length}</strong> ${matLabel}` : '',
    allNorm.length ? `<strong>${allNorm.length}</strong> ${isCamaraAg?'sancionadas':'normas'}` : '',
    isCamaraAg&&emendas.length ? `<strong>${emendas.length}</strong> emendas` : '',
    comissoes.length ? `<strong>${comissoes.length}</strong> comissões` : '',
    relatorias.length ? `<strong>${relatorias.length}</strong> relatorias` : '',
    frentes.length  ? `<strong>${frentes.length}</strong> frentes` : '',
  ].filter(Boolean).join(' · ');

  let h='<div class="agente-wrap">';
  h+=`<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 16px;background:var(--accent-light);border-radius:10px;border:1px solid #bbf7d0">
    <div style="font-size:13px;color:var(--muted);line-height:1.6">
      <strong style="color:var(--accent-dark)">Agente IA</strong> — Análise completa de <strong>${esc(nomeParlamentar)}</strong>.<br>
      Contexto carregado: ${ctxSummary||'dados básicos'}.
    </div>
    <button onclick="agenteHistoryClear()" style="flex-shrink:0;display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:7px;border:1.5px solid #fca5a5;background:#fff7f7;color:#dc2626;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap;transition:background .15s" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fff7f7'"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg> Limpar conversa</button>
  </div>`;
  h+='<div class="agente-chat">';
  h+='<div class="agente-msgs" id="agenteMsgs">';
  const welcomeExtras = [
    allMat.length ? `${isCamaraAg?'proposições':'matérias'} (quantidade, tipos, anos, temas)` : '',
    allNorm.length ? (isCamaraAg?'proposições sancionadas (leis aprovadas)':'normas jurídicas') : '',
    isCamaraAg&&emendas.length ? 'emendas orçamentárias de todos os anos (valores, destinos, funções)' : '',
    comissoes.length ? 'comissões e participação' : '',
    frentes.length  ? 'frentes parlamentares' : '',
  ].filter(Boolean);
  h+=`<div class="agente-msg"><div class="agente-av ia">IA</div><div class="agente-bubble ia"><p>Olá! Sou o Agente IA de análise legislativa. Tenho dados completos de <strong>${esc(nomeParlamentar)}</strong> sobre: ${welcomeExtras.join(', ')||'produção legislativa'}.</p><p>Posso analisar ranking por tema, produtividade por ano, buscar proposições específicas, comparar períodos e muito mais.</p></div></div>`;
  h+='</div>';
  h+='<div class="agente-input-bar">';
  h+=`<textarea id="agenteInput" class="agente-textarea" placeholder="Pergunte sobre ${isCamaraAg?'proposições, normas sancionadas, emendas':'matérias, normas'}, comissões, frentes..." onkeydown="agenteKeydown(event)" oninput="this.style.height='40px';this.style.height=Math.min(this.scrollHeight,120)+'px'"></textarea>`;
  h+='<button id="agenteSend" class="agente-send" onclick="agenteSend()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button>';
  h+='</div></div></div>';

  return h;
}

function groupByTipo(items) {
  const g={};
  items.forEach(i=>{ const t=extractTipo(i.__str__||''); g[t]=(g[t]||0)+1; });
  return g;
}
function groupByYear(items) {
  const g={};
  items.forEach(i=>{ const y=extractYear(i.__str__||''); if(y) g[y]=(g[y]||0)+1; });
  return g;
}

async function initAgenteEvents() {
  await agenteHistoryLoad(agenteHistoryParlId);
  const inp=document.getElementById('agenteInput');
  if(inp) inp.focus();
}

function agenteKeydown(e) {
  if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); agenteSend(); }
}

function agenteAddMsg(role,html) {
  const area=document.getElementById('agenteMsgs');
  if(!area) return;
  const row=document.createElement('div');
  row.className='agente-msg '+role;
  row.innerHTML=`<div class="agente-av ${role==='user'?'user':'ia'}">${role==='user'?'EU':'IA'}</div><div class="agente-bubble ${role==='user'?'user':'ia'}">${html}</div>`;
  area.appendChild(row);
  area.scrollTop=area.scrollHeight;
}

function agenteAddTyping() {
  const area=document.getElementById('agenteMsgs');
  if(!area) return;
  const row=document.createElement('div');
  row.className='agente-msg'; row.id='agenteTyping';
  row.innerHTML='<div class="agente-av ia">IA</div><div class="agente-bubble ia" style="padding:10px 14px;display:inline-flex;min-width:0"><div class="agente-typing-dots"><div class="agente-dot"></div><div class="agente-dot"></div><div class="agente-dot"></div></div></div>';
  area.appendChild(row);
  area.scrollTop=area.scrollHeight;
}

function agenteFmt(text) {
  let s=text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>')
    .replace(/\*(.*?)\*/g,'<em>$1</em>')
    .replace(/^[\-\*] (.+)/gm,'<li>$1</li>');
  s=s.replace(/(<li>.*<\/li>)/gs,m=>'<ul>'+m+'</ul>');
  return s.split(/\n\n+/).map(p=>p.startsWith('<ul>')?p:'<p>'+p.replace(/\n/g,'<br>')+'</p>').join('');
}

async function agenteSend() {
  const inp=document.getElementById('agenteInput');
  const btn=document.getElementById('agenteSend');
  if(!inp||agenteBusy) return;
  const q=inp.value.trim();
  if(!q) return;

  agenteBusy=true;
  if(btn) btn.disabled=true;
  agenteAddMsg('user','<p>'+esc(q)+'</p>');
  inp.value=''; inp.style.height='';
  agenteAddTyping();

  const sysContent=`Você é um assistente especializado em análise do perfil parlamentar de legisladores brasileiros.
Responda APENAS com base nos dados fornecidos abaixo. Seja objetivo, claro e use linguagem simples. Responda em português brasileiro.
Você TEM MEMÓRIA desta conversa — lembre-se de tudo que foi dito anteriormente.
Você tem acesso a dados completos do parlamentar: proposições/matérias legislativas, normas sancionadas (leis aprovadas), emendas orçamentárias (valores, destinos, funções), comissões, filiações, frentes parlamentares e mandatos.
Use esses dados para: buscar itens por tema/palavra-chave, filtrar por tipo/ano, calcular totais e rankings, identificar padrões, comparar períodos, listar emendas por localidade ou função.
NÃO invente matérias, normas ou emendas que não constem nos dados fornecidos.
IMPORTANTE: Se solicitado a listar muitos itens (mais de 50), SEMPRE entregue a lista completa em uma única resposta, sem cortar ou pedir confirmação para continuar.

${agenteContext||'Dados não disponíveis.'}`;

  agenteHistory.push({role:'user',content:q});

  // Limita histórico para não estourar contexto da API
  while(agenteHistory.length > MAX_AGENTE_TURNS * 2) agenteHistory.splice(0,2);

  const messages=[{role:'system',content:sysContent},...agenteHistory];

  try{
    const fd=new FormData();
    fd.append('_token',APP_CONFIG.csrf||'');
    fd.append('projeto_id',APP_CONFIG.projetoId||'');
    fd.append('messages',JSON.stringify(messages));
    const res=await fetch(APP_CONFIG.openaiUrl,{method:'POST',body:fd});
    const data=await res.json();
    document.getElementById('agenteTyping')?.remove();
    if(data.error){
      agenteHistory.pop();
      agenteHistorySave();
      const errMsg = typeof data.error==='string' ? data.error : (data.error?.message||JSON.stringify(data.error));
      agenteAddMsg('ia','<p style="color:var(--red)">'+esc(errMsg)+'</p>');
    }else{
      const text=data.choices?.[0]?.message?.content||'';
      if(!text){
        agenteHistory.pop();
        agenteHistorySave();
        agenteAddMsg('ia','<p>Sem resposta.</p>');
      }else{
        agenteHistory.push({role:'assistant',content:text});
        agenteHistorySave();
        agenteAddMsg('ia',agenteFmt(text));
      }
    }
  }catch(e){
    agenteHistory.pop();
    agenteHistorySave();
    document.getElementById('agenteTyping')?.remove();
    agenteAddMsg('ia','<p style="color:var(--red)">Falha na comunicação com o servidor.</p>');
  }
  agenteBusy=false;
  if(btn) btn.disabled=false;
  document.getElementById('agenteInput')?.focus();
}

// ══════════════════════════════════════════════════════
// GLOBAL DASHBOARD
// ══════════════════════════════════════════════════════
let globalDashFilters = {ano:'', partido:'', uf:'', parlamentar:'', ativo:'1'};
let globalDashData = null;

function fmtCompact(v) {
  return Number(v||0).toLocaleString('pt-BR');
}
function fmtMoney(v) {
  const n = Number(v||0);
  if(n >= 1e9) return 'R$ ' + (n/1e9).toLocaleString('pt-BR',{maximumFractionDigits:1}) + ' bi';
  if(n >= 1e6) return 'R$ ' + (n/1e6).toLocaleString('pt-BR',{maximumFractionDigits:1}) + ' mi';
  return n > 0 ? 'R$ ' + n.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}) : '—';
}
function fmtChartValue(v, money=false) {
  const n = Number(v || 0);
  if(money) return fmtMoney(n);
  if(Math.abs(n) >= 1e9) return Math.round(n / 1e9).toLocaleString('pt-BR') + ' bi';
  if(Math.abs(n) >= 1e6) return Math.round(n / 1e6).toLocaleString('pt-BR') + ' mi';
  return fmtCompact(n);
}

async function fetchGlobalDashboard() {
  const qs = new URLSearchParams({source: APP_CONFIG.source || 'cmjp'});
  Object.entries(globalDashFilters).forEach(([k,v]) => { if(v !== '' && v !== null && v !== undefined) qs.set(k, v); });
  const res = await fetch((APP_CONFIG.basePath||'') + '/api/dashboard-global?' + qs.toString());
  if(!res.ok) throw new Error('Falha ao carregar dashboard global');
  return await res.json();
}

async function openGlobalDashboard() {
  history.replaceState(null, '', '#dashboard-global');
  updateParlamentaresNavState();
  currentProfile = null;
  tablePages = {};
  dashboardAllMaterias = null;
  dashboardAllNormas = null;
  dashboardAllEmendas = null;
  dashboardChartInstances = {};
  const main = document.getElementById('mainContent');
  main.innerHTML = `<div class="tab-loader" style="min-height:260px"><div class="spinner"></div><span style="color:var(--muted);font-size:13px">Carregando dashboard global...</span></div>`;
  try {
    globalDashData = await fetchGlobalDashboard();
    renderGlobalDashboard();
    setTimeout(renderGlobalDashboardCharts, 0);
  } catch(e) {
    main.innerHTML = `<div class="empty-tab"><i class="ph ph-warning-circle" style="font-size:32px;color:var(--red);display:block;margin-bottom:10px"></i><p style="font-weight:700;color:var(--red);margin-bottom:6px">Erro ao carregar dashboard global</p><p style="font-size:12px;color:var(--muted)">${esc(e.message)}</p></div>`;
  }
}

function updateParlamentaresNavState() {
  const isGlobal = location.hash === '#dashboard-global';
  document.querySelectorAll('[data-nav="producao-legislativa"]').forEach(el => el.classList.toggle('active', isGlobal));
  document.querySelectorAll('[data-nav="parlamentares"]').forEach(el => el.classList.toggle('active', !isGlobal));
}

function renderGlobalDashboard() {
  const data = globalDashData || {};
  const k = data.kpis || {};
  const f = data.filters || {};
  const selected = f.selected || globalDashFilters;
  const periodo = f.periodo || {inicio:2023, fim:2026};
  const periodoLabel = `${periodo.inicio || 2023}-${periodo.fim || 2026}`;
  const isCamaraDash = APP_CONFIG.source === 'camara_federal';
  const matLabel = isCamaraDash ? 'Proposições' : 'Matérias';
  const normLabel = isCamaraDash ? 'Sancionadas' : 'Normas';

  const kpi = (label, value, cls='', money=false) =>
    `<div class="global-kpi ${cls}"><div class="global-kpi-label">${esc(label)}</div><div class="global-kpi-value ${money?'global-kpi-money':''}">${money ? fmtMoney(value) : fmtCompact(value)}</div></div>`;
  const chartCard = (id, title, height=280, extra='') => {
    const scroll = extra.includes('scroll');
    const items = extra.match(/items-(\d+)/)?.[1] || 10;
    const frameStyle = scroll ? `--chart-items:${items}` : `height:${height}px`;
    const frame = `<div class="global-chart-frame" style="${frameStyle}"><canvas id="${id}"></canvas></div>`;
    return `<div class="global-chart-card ${extra}"><h3 class="global-chart-title">${esc(title)}</h3>${scroll ? `<div class="global-chart-scroll">${frame}</div>` : frame}</div>`;
  };
  const renderParlFilter = () => {
    const parls = f.parlamentares || [];
    const selectedId = String(selected.parlamentar || '');
    const current = parls.find(p => String(p.id) === selectedId);
    let html = `<div class="global-combo" id="globalParlCombo" onclick="event.stopPropagation()">
      <button type="button" class="global-combo-btn" onclick="toggleGlobalParlFilter(event)">
        <span class="global-combo-label">${esc(current ? current.nome : 'Todos os parlamentares')}</span>
        <i class="ph ph-caret-down global-combo-caret"></i>
      </button>
      <div class="global-combo-menu">
        <div class="global-combo-search"><input type="text" id="globalParlSearch" placeholder="Pesquisar parlamentar..." oninput="filterGlobalParlOptions(this.value)"></div>
        <div class="global-combo-list" id="globalParlList">`;
    html += `<button type="button" class="global-combo-item ${selectedId===''?'active':''}" data-search="todos parlamentares" onclick="selectGlobalDashParlamentar('')"><span class="global-combo-item-main">Todos os parlamentares</span></button>`;
    parls.forEach(p => {
      const meta = [p.partido, p.uf].filter(Boolean).join('/');
      const search = `${p.nome || ''} ${p.partido || ''} ${p.uf || ''}`.toLowerCase();
      html += `<button type="button" class="global-combo-item ${String(p.id)===selectedId?'active':''}" data-search="${esc(search)}" onclick="selectGlobalDashParlamentar('${p.id}')">
        <span class="global-combo-item-main">${esc(p.nome || '')}</span>
        ${meta ? `<span class="global-combo-item-meta">${esc(meta)}</span>` : ''}
      </button>`;
    });
    html += '</div></div></div>';
    return html;
  };

  let h = '<div class="global-dashboard">';

  h += '<div class="global-filter-bar">';
  h += `<select class="dash-filter-select" onchange="changeGlobalDashFilter('ano',this.value)"><option value="">${esc(periodoLabel)}</option>`;
  (f.anos||[]).forEach(y => { h += `<option value="${y}"${String(selected.ano||'')===String(y)?' selected':''}>${y}</option>`; });
  h += '</select>';
  h += `<select class="dash-filter-select" onchange="changeGlobalDashFilter('partido',this.value)"><option value="">Todos os partidos</option>`;
  (f.partidos||[]).forEach(p => { h += `<option value="${esc(p)}"${selected.partido===p?' selected':''}>${esc(p)}</option>`; });
  h += '</select>';
  h += `<select class="dash-filter-select" onchange="changeGlobalDashFilter('uf',this.value)"><option value="">Todas as UFs</option>`;
  (f.ufs||[]).forEach(uf => { h += `<option value="${esc(uf)}"${selected.uf===uf?' selected':''}>${esc(uf)}</option>`; });
  h += '</select>';
  h += renderParlFilter();
  h += `<select class="dash-filter-select" onchange="changeGlobalDashFilter('ativo',this.value)">
    <option value="1"${selected.ativo==='1'?' selected':''}>Apenas ativos</option>
    <option value=""${selected.ativo===''?' selected':''}>Todos</option>
    <option value="0"${selected.ativo==='0'?' selected':''}>Apenas inativos</option>
  </select>`;
  h += `<button class="global-clear-btn" onclick="clearGlobalDashFilters()">Limpar filtros</button>`;
  h += '</div>';

  h += '<div class="global-kpi-grid">';
  h += kpi('Parlamentares', k.parlamentares, 'primary');
  h += kpi(matLabel, k.materias, 'primary');
  h += kpi(normLabel, k.normas);
  h += kpi('Comissões', k.comissoes);
  h += kpi('Frentes', k.frentes);
  h += kpi('Emendas', k.emendas);
  h += kpi('Valor empenhado', k.empenhado, 'money', true);
  h += kpi('Valor liquidado', k.liquidado, 'money', true);
  h += kpi('Valor pago', k.pago, 'money', true);
  h += '</div>';

  h += '<section class="global-section"><h2 class="global-section-title">Produção por ano</h2><div class="global-chart-grid">';
  h += chartCard('chartGlobalMatAno', `${matLabel} por ano`);
  h += chartCard('chartGlobalNormAno', `${normLabel} por ano`);
  h += '</div></section>';

  h += '<section class="global-section"><h2 class="global-section-title">Produção por tipo</h2><div class="global-chart-grid">';
  h += chartCard('chartGlobalMatTipo', `${matLabel} por tipo`, 280, `scroll items-${Math.max(10, (data.charts?.materias_por_tipo || []).length)}`);
  h += chartCard('chartGlobalNormTipo', `${normLabel} por tipo`);
  h += '</div></section>';

  h += '<section class="global-section"><h2 class="global-section-title">Distribuição parlamentar</h2><div class="global-chart-grid">';
  h += chartCard('chartGlobalPartido', `${matLabel} por partido`, 280, `scroll items-${Math.max(10, (data.charts?.producao_por_partido || []).length)}`);
  h += chartCard('chartGlobalUf', `${matLabel} por UF`, 280, `scroll items-${Math.max(10, (data.charts?.producao_por_uf || []).length)}`);
  h += '</div></section>';

  if(isCamaraDash || (k.emendas||0) > 0) {
    h += '<section class="global-section"><h2 class="global-section-title">Emendas</h2><div class="global-chart-grid">';
    h += chartCard('chartGlobalEmFuncao', 'Emendas por função', 280, `scroll items-${Math.max(10, (data.charts?.emendas_por_funcao || []).length)}`);
    h += chartCard('chartGlobalEmLocal', 'Top localidades', 280, `scroll items-${Math.max(10, (data.charts?.emendas_por_localidade || []).length)}`);
    h += '</div></section>';
  }

  h += '<section class="global-section"><h2 class="global-section-title">Rankings</h2><div class="global-rank-grid">';
  h += renderGlobalRankBox('Maior produção legislativa', data.rankings?.producao || [], 'materias', matLabel);
  h += renderGlobalRankBox(`Mais ${normLabel.toLowerCase()}`, data.rankings?.sancionadas || [], 'normas', normLabel);
  h += renderGlobalRankBox('Mais emendas', data.rankings?.emendas_quantidade || [], 'emendas', 'Emendas');
  h += renderGlobalRankBox('Maior valor empenhado', data.rankings?.emendas_valor || [], 'empenhado', 'Empenhado', true);
  h += '</div></section></div>';

  document.getElementById('mainContent').innerHTML = h;
}

function renderGlobalRankBox(title, rows, field, label, money=false) {
  let h = `<div class="global-rank-card"><div class="global-table-title"><h3 class="section-title">${esc(title)}</h3></div>`;
  if(!rows.length) return h + '<div class="empty-tab">Nenhum dado para os filtros selecionados.</div></div>';
  h += '<div class="table-wrap-inner global-rank-scroll"><table class="global-rank-table"><thead><tr><th>#</th><th>Parlamentar</th><th>UF/Partido</th><th style="text-align:right">'+esc(label)+'</th><th></th></tr></thead><tbody>';
  rows.forEach((r,i) => {
    const val = money ? fmtMoney(r[field]) : fmtCompact(r[field]);
    const party = [r.partido, r.uf].filter(Boolean).join('/');
    h += `<tr><td>${i+1}</td><td><span class="global-rank-name" title="${esc(r.nome)}">${esc(r.nome)}</span></td><td><span class="global-rank-party">${esc(party || '—')}</span></td><td class="global-rank-value">${esc(val)}</td><td style="text-align:right"><button class="btn-ver" onclick="openProfile(${r.id})">Ver</button></td></tr>`;
  });
  return h + '</tbody></table></div></div>';
}

async function changeGlobalDashFilter(key, value) {
  globalDashFilters[key] = value;
  if(key === 'partido' || key === 'uf') globalDashFilters.parlamentar = '';
  await refreshGlobalDashboard();
}

function clearGlobalDashFilters() {
  globalDashFilters = {ano:'', partido:'', uf:'', parlamentar:'', ativo:'1'};
  refreshGlobalDashboard();
}

function toggleGlobalParlFilter(e) {
  if(e) e.stopPropagation();
  const combo = document.getElementById('globalParlCombo');
  if(!combo) return;
  combo.classList.toggle('open');
  if(combo.classList.contains('open')) {
    setTimeout(() => document.getElementById('globalParlSearch')?.focus(), 0);
  }
}

function filterGlobalParlOptions(term) {
  const list = document.getElementById('globalParlList');
  if(!list) return;
  const q = String(term || '').trim().toLowerCase();
  let visible = 0;
  list.querySelectorAll('.global-combo-item').forEach(btn => {
    const ok = !q || (btn.getAttribute('data-search') || '').includes(q);
    btn.style.display = ok ? 'flex' : 'none';
    if(ok) visible++;
  });
  let empty = list.querySelector('.global-combo-empty');
  if(!visible) {
    if(!empty) {
      empty = document.createElement('div');
      empty.className = 'global-combo-empty';
      empty.textContent = 'Nenhum parlamentar encontrado';
      list.appendChild(empty);
    }
  } else if(empty) {
    empty.remove();
  }
}

function selectGlobalDashParlamentar(id) {
  globalDashFilters.parlamentar = id || '';
  document.getElementById('globalParlCombo')?.classList.remove('open');
  refreshGlobalDashboard();
}

document.addEventListener('click', function(e) {
  const combo = document.getElementById('globalParlCombo');
  if(combo && !combo.contains(e.target)) combo.classList.remove('open');
});

async function refreshGlobalDashboard() {
  const main = document.getElementById('mainContent');
  main.innerHTML = `<div class="tab-loader" style="min-height:260px"><div class="spinner"></div><span style="color:var(--muted);font-size:13px">Atualizando dashboard...</span></div>`;
  try {
    globalDashData = await fetchGlobalDashboard();
    renderGlobalDashboard();
    setTimeout(renderGlobalDashboardCharts, 0);
  } catch(e) {
    main.innerHTML = `<div class="empty-tab"><i class="ph ph-warning-circle" style="font-size:32px;color:var(--red);display:block;margin-bottom:10px"></i><p style="font-weight:700;color:var(--red);margin-bottom:6px">Erro ao atualizar dashboard</p><p style="font-size:12px;color:var(--muted)">${esc(e.message)}</p></div>`;
  }
}

function renderGlobalDashboardCharts() {
  if(typeof Chart === 'undefined' || !globalDashData) return;
  Object.values(dashboardChartInstances).forEach(c=>{try{c.destroy()}catch(e){}});
  dashboardChartInstances = {};
  const charts = globalDashData.charts || {};
  const COLORS=['#1A6B4F','#C9A84C','#3B82F6','#EC4899','#8B5CF6','#F59E0B','#10B981','#EF4444','#0EA5E9','#A855F7','#64748B','#14B8A6'];
  const font={family:"'Inter',sans-serif",size:11};
  const moneyTick = v => Number(v)>=1e9?'R$'+(Number(v)/1e9).toFixed(1)+'B':Number(v)>=1e6?'R$'+(Number(v)/1e6).toFixed(1)+'M':Number(v)>=1e3?'R$'+(Number(v)/1e3).toFixed(0)+'K':'R$'+Number(v).toFixed(0);

  function mount(id, type, series, opts={}) {
    const canvas = document.getElementById(id);
    if(!canvas) return;
    const wrap = canvas.parentElement;
    if(!series || !series.length) {
      canvas.style.display = 'none';
      if(wrap && !wrap.querySelector('.chart-empty-msg')) {
        const d = document.createElement('div');
        d.className = 'chart-empty-msg';
        d.style.cssText = 'display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);font-size:12px;text-align:center;padding:8px';
        d.textContent = 'Nenhum dado para os filtros selecionados';
        wrap.appendChild(d);
      }
      return;
    }
    const maxLabel = opts.maxLabel || (opts.horizontal ? 36 : 18);
    const labels = series.map(x => String(x.label || '').length > maxLabel ? String(x.label).slice(0,maxLabel-1)+'…' : String(x.label || ''));
    const values = series.map(x => opts.money ? Number(x.empenhado||0) : Number(x.total||0));
    const categoryTicks = {font, autoSkip:true, maxTicksLimit:opts.maxTicks||10, callback:function(value){ return this.getLabelForValue(value); }};
    const valueTicks = opts.money ? {font, callback:moneyTick} : {font};
    const scales = opts.horizontal
      ? {x:{beginAtZero:true,ticks:{...valueTicks,display:false},grid:{display:false},border:{display:false}},y:{ticks:{...categoryTicks,autoSkip:false},grid:{display:false}}}
      : {y:{beginAtZero:true,ticks:valueTicks,grid:{color:'rgba(0,0,0,.05)'}},x:{ticks:{...categoryTicks,maxRotation:0,minRotation:0},grid:{display:false}}};
    const valueLabelPlugin = {
      id: 'valueLabel_' + id,
      afterDatasetsDraw(chart) {
        if(type !== 'bar' || (!opts.horizontal && !opts.showValues)) return;
        const {ctx} = chart;
        ctx.save();
        ctx.font = "700 11px Inter, sans-serif";
        ctx.fillStyle = '#374151';
        ctx.textAlign = opts.horizontal ? 'left' : 'center';
        ctx.textBaseline = opts.horizontal ? 'middle' : 'bottom';
        chart.getDatasetMeta(0).data.forEach((bar, index) => {
          const value = values[index];
          if(!value) return;
          const label = fmtChartValue(value, opts.money);
          ctx.fillText(label, opts.horizontal ? bar.x + 6 : bar.x, opts.horizontal ? bar.y : bar.y - 6);
        });
        ctx.restore();
      }
    };
    dashboardChartInstances[id] = new Chart(canvas, {
      type,
      data:{labels,datasets:[{label:opts.label||'Total',data:values,backgroundColor:type==='line'?'#1A6B4F':labels.map((_,i)=>COLORS[i%COLORS.length]),borderColor:'#1A6B4F',borderRadius:4,tension:.25,fill:false}]},
      options:{indexAxis:opts.horizontal?'y':'x',responsive:true,maintainAspectRatio:false,layout:{padding:opts.horizontal?{right:64}:{top:opts.showValues?18:0}},plugins:{legend:{display:false},barValue:false,tooltip:{callbacks:{label:ctx=>' '+(opts.money?fmtMoney(ctx.parsed[opts.horizontal?'x':'y']):fmtChartValue(ctx.parsed[opts.horizontal?'x':'y']))}}},scales},
      plugins:[valueLabelPlugin]
    });
  }

  mount('chartGlobalMatAno', 'bar', charts.materias_por_ano, {label:'Produção', maxTicks:4, showValues:true});
  mount('chartGlobalMatTipo', 'bar', charts.materias_por_tipo, {label:'Tipos', horizontal:true, maxLabel:34});
  mount('chartGlobalPartido', 'bar', charts.producao_por_partido, {label:'Partidos', horizontal:true, maxLabel:34});
  mount('chartGlobalUf', 'bar', charts.producao_por_uf, {label:'UFs', horizontal:true, maxLabel:18});
  mount('chartGlobalNormAno', 'bar', charts.normas_por_ano, {label:'Sancionadas', maxTicks:4, showValues:true});
  mount('chartGlobalNormTipo', 'bar', charts.normas_por_tipo, {label:'Tipos', horizontal:true, maxLabel:34});
  mount('chartGlobalEmFuncao', 'bar', charts.emendas_por_funcao, {label:'Empenhado', money:true, horizontal:true, maxLabel:34});
  mount('chartGlobalEmLocal', 'bar', charts.emendas_por_localidade, {label:'Empenhado', money:true, horizontal:true, maxLabel:36});
}

// ══════════════════════════════════════════════════════
// Events
// ══════════════════════════════════════════════════════
function onChangeLeg(v){selectedLeg=v;loadMandatos().then(()=>renderList())}
let st;
function onSearch(v){search=v;clearTimeout(st);st=setTimeout(()=>renderGrid(),150)}
function togglePartidoDropdown(){
  const c=document.getElementById('partidoCombo');
  if(!c)return;
  const opening=!c.classList.contains('open');
  c.classList.toggle('open');
  if(opening){
    const inp=c.querySelector('.global-combo-search input');
    if(inp){inp.value='';filterPartidoOptions('');inp.focus();}
  }
}
function togglePartidoItem(sigla){
  if(selectedPartidos.has(sigla))selectedPartidos.delete(sigla);
  else selectedPartidos.add(sigla);
  renderList();
  // reabre o dropdown após re-render
  const c=document.getElementById('partidoCombo');
  if(c){c.classList.add('open');setTimeout(()=>{const inp=c.querySelector('.global-combo-search input');if(inp)inp.focus();},0);}
}
function filterPartidoOptions(term){
  const list=document.getElementById('partidoList');
  if(!list)return;
  const q=(term||'').toLowerCase();
  list.querySelectorAll('.global-combo-item').forEach(btn=>{
    const s=btn.getAttribute('data-search')||'';
    btn.style.display=(!q||s.includes(q)||btn.style.color==='rgb(220, 38, 38)')?'flex':'none';
  });
}
function clearPartidos(){selectedPartidos=new Set();renderList();}
document.addEventListener('click',()=>{document.getElementById('partidoCombo')?.classList.remove('open');});
function toggleActive(){onlyActive=!onlyActive;renderGrid();document.querySelector('[onclick="toggleActive()"]')?.classList.toggle('active',onlyActive)}
function toggleTitular(){onlyTitular=!onlyTitular;renderGrid();document.querySelector('[onclick="toggleTitular()"]')?.classList.toggle('active',onlyTitular)}

async function openProfile(id) {
  const p=allParlamentares.find(x=>x.id===id);if(!p)return;
  history.replaceState(null,'','#perfil-'+id);
  currentProfile=p;activeTab='inicio';tablePages={};
  dashboardAllMaterias=null;dashboardAllNormas=null;dashboardChartInstances={};
  globalDashData=null;
  agenteContext=null;agenteBusy=false;agenteHistory=[];agenteHistoryParlId=null;
  const main=document.getElementById("mainContent");
  main.innerHTML=await renderProfileShell(p);
  switchTab('inicio');
  window.scrollTo({top:0,behavior:'smooth'});

  // Preenche partido sem bloquear a abertura do perfil
  getCurrentParty(p.id).then(partido=>{
    if(!partido) return;
    const slot=document.getElementById('party-slot');
    const row=document.getElementById('party-row');
    if(!slot||!row) return;
    const pc=partyColor(partido);
    slot.innerHTML=`<span class="card-party" style="background:${pc[0]};color:${pc[1]}">${esc(partido)}</span>`;
    row.style.display='flex';
  }).catch(()=>{});

  // Pré-carrega dados das abas mais pesadas em background
  getAutorData(p).catch(()=>{});
  getCached(p.id,'mandatos',()=>fetchAllPages(`/parlamentares/mandato/?parlamentar=${p.id}`)).catch(()=>{});
}

function restoreHashProfile() {
  const m = location.hash.match(/^#perfil-(\d+)$/);
  if(!m) return false;
  const id = parseInt(m[1],10);
  const p  = allParlamentares.find(x=>x.id===id);
  if(p){ openProfile(p.id); return true; }
  return false;
}

function restoreHashView() {
  if(location.hash === '#dashboard-global') { openGlobalDashboard(); return true; }
  updateParlamentaresNavState();
  return restoreHashProfile();
}

function backToList(){
  history.replaceState(null,'',location.pathname+location.search);
  updateParlamentaresNavState();
  currentProfile=null;tabDataCache={};
  dashboardAllMaterias=null;dashboardAllNormas=null;dashboardChartInstances={};
  globalDashData=null;
  renderList();window.scrollTo({top:0,behavior:"smooth"});
}

window.addEventListener('hashchange', () => {
  if(location.hash === '#dashboard-global') {
    openGlobalDashboard();
  } else if(!location.hash) {
    updateParlamentaresNavState();
    if(!currentProfile) renderList();
  } else {
    restoreHashView();
  }
});

async function loadMandatos(){
  if(!selectedLeg){mandatosByLeg=[];return}
  try{mandatosByLeg=await fetchAllPages("/parlamentares/mandato/?legislatura="+selectedLeg)}catch(e){mandatosByLeg=[]}
}

// ══════════════════════════════════════════════════════
// Init
// ══════════════════════════════════════════════════════
// ── Sincronização com progresso via SSE ──
function abrirSincronizacao() {
  if(document.getElementById('sync-overlay')) return;
  const src = APP_CONFIG.source || 'cmjp';

  const overlay = document.createElement('div');
  overlay.id = 'sync-overlay';
  overlay.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;display:flex;align-items:center;justify-content:center';
  overlay.innerHTML=`
    <div style="background:var(--surface);border-radius:16px;padding:32px 36px;width:420px;max-width:92vw;box-shadow:0 8px 40px rgba(0,0,0,.25)">
      <h3 style="margin:0 0 6px;font-size:17px;color:var(--text)"><i class="ph ph-cloud-arrow-down" style="color:var(--accent)"></i> Sincronizando fonte</h3>
      <p id="sync-label" style="margin:0 0 18px;font-size:13px;color:var(--muted)">Iniciando...</p>
      <div style="background:var(--bg);border-radius:99px;height:10px;overflow:hidden;margin-bottom:10px">
        <div id="sync-bar" style="height:100%;width:0%;background:var(--accent);border-radius:99px;transition:width .3s"></div>
      </div>
      <p id="sync-pct" style="margin:0 0 20px;font-size:12px;color:var(--muted);text-align:right">0%</p>
      <button id="sync-cancel" onclick="cancelarSincronizacao()" style="padding:8px 18px;border-radius:8px;border:1.5px solid var(--border);background:transparent;color:var(--muted);font-size:13px;font-family:inherit;cursor:pointer">Cancelar</button>
    </div>`;
  document.body.appendChild(overlay);

  const nomesRecurso = {parlamentares:'Parlamentares', legislaturas:'Legislaturas', partidos:'Partidos'};
  const label = document.getElementById('sync-label');
  const bar   = document.getElementById('sync-bar');
  const pct   = document.getElementById('sync-pct');

  const es = new EventSource(APP_CONFIG.basePath+'/api/cache/sincronizar?source='+encodeURIComponent(src));
  overlay._es = es;

  es.onmessage = (e) => {
    const d = JSON.parse(e.data);
    const p = d.total > 0 ? Math.round(d.done / d.total * 100) : 0;
    bar.style.width = p + '%';
    pct.textContent = p + '%';
    if(d.status === 'iniciando') {
      label.textContent = `Calculando páginas... (${d.total} páginas no total)`;
    } else if(d.status === 'progresso') {
      label.textContent = `Sincronizando ${nomesRecurso[d.recurso]||d.recurso}... (${d.done}/${d.total})`;
    } else if(d.status === 'concluido') {
      bar.style.width = '100%';
      pct.textContent = '100%';
      label.textContent = 'Sincronização concluída!';
      document.getElementById('sync-cancel').textContent = 'Fechar';
      es.close();
      // Limpa localStorage e recarrega via bulk
      Object.keys(localStorage).filter(k=>k.startsWith('kc_')).forEach(k=>localStorage.removeItem(k));
      tabDataCache={};
      setTimeout(()=>{ fecharSincronizacao(); init(); }, 1200);
    }
  };

  es.onerror = () => {
    label.textContent = 'Erro na sincronização. Verifique a conexão.';
    label.style.color = 'var(--red)';
    document.getElementById('sync-cancel').textContent = 'Fechar';
    es.close();
  };
}

function cancelarSincronizacao() {
  const overlay = document.getElementById('sync-overlay');
  if(overlay?._es) overlay._es.close();
  fecharSincronizacao();
}

function fecharSincronizacao() {
  document.getElementById('sync-overlay')?.remove();
}

async function fetchBulk() {
  const src = APP_CONFIG.source || 'cmjp';
  try {
    const res = await fetch(APP_CONFIG.basePath+'/api/bulk?source='+encodeURIComponent(src));
    if(!res.ok) return {fromCache:false};
    return await res.json();
  } catch(e) { return {fromCache:false}; }
}

async function init(){
  const lt=document.getElementById("loaderText"),pf=document.getElementById("progressFill");
  try{
    // 1. localStorage (sessão atual)
    const cached = storageLoad();
    if(cached){
      lt.textContent="Carregando parlamentares...";
      allLegislaturas = cached.legislaturas;
      allParlamentares = cached.parlamentares;
      cached.partidos.forEach(p=>{allPartidos[p.id]=p.nome;allPartidosSigla[p.id]=p.sigla});
      selectedLeg = allLegislaturas[0] ? String(allLegislaturas[0].id) : "";
      mandatosByLeg = cached.mandatos || [];
      if(!restoreHashView()) renderList();
      return;
    }

    // 2. Cache do servidor (PHP SaplCache via /api/bulk)
    lt.textContent="Carregando parlamentares...";
    const bulk = await fetchBulk();
    if(bulk.fromCache && bulk.parlamentares?.length) {
      lt.textContent="Carregando parlamentares...";
      allLegislaturas = (bulk.legislaturas||[]).sort((a,b)=>(b.numero||0)-(a.numero||0));
      allParlamentares = bulk.parlamentares;
      bulk.partidos?.forEach(p=>{allPartidos[p.id]=p.nome;allPartidosSigla[p.id]=p.sigla});
      selectedLeg = allLegislaturas[0] ? String(allLegislaturas[0].id) : "";
      await loadMandatos();
      storageSave({
        legislaturas: allLegislaturas,
        parlamentares: allParlamentares,
        partidos: bulk.partidos||[],
        mandatos: mandatosByLeg,
        selectedLeg,
      });
      if(!restoreHashView()) renderList();
      return;
    }

    // 3. API do SAPL (fetch completo)
    lt.textContent="Carregando dados iniciais...";
    const [legsRaw,partidosRaw]=await Promise.all([
      fetchAllPages("/parlamentares/legislatura/"),
      fetchAllPages("/parlamentares/partido/"),
    ]);
    allLegislaturas=legsRaw.sort((a,b)=>(b.numero||0)-(a.numero||0));
    partidosRaw.forEach(p=>{allPartidos[p.id]=p.nome;allPartidosSigla[p.id]=p.sigla});
    if(allLegislaturas.length) selectedLeg=String(allLegislaturas[0].id);

    lt.textContent="Carregando parlamentares...";
    const [parls]=await Promise.all([
      fetchAllPages("/parlamentares/parlamentar/",(done,total)=>{
        lt.textContent=`Carregando parlamentares... ${done}/${total}`;
        if(pf) pf.style.width=Math.round(done/total*100)+"%";
      }),
      loadMandatos(),
    ]);
    allParlamentares=parls;

    if(!parls.length){
      document.getElementById("mainContent").innerHTML=`<div class="empty">
        <i class="ph ph-wifi-x" style="font-size:40px;color:var(--muted);opacity:.5;display:block;margin-bottom:14px"></i>
        <p style="font-size:15px;font-weight:600;color:var(--text);margin-bottom:6px">API não retornou parlamentares</p>
        <p style="font-size:13px;color:var(--muted);margin-bottom:18px">O servidor pode estar indisponível. Tente novamente em alguns instantes.</p>
        <button onclick="location.reload()" style="padding:9px 20px;border-radius:9px;border:none;background:var(--accent);color:#fff;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer"><i class="ph ph-arrows-clockwise"></i> Tentar novamente</button>
      </div>`;
      return;
    }

    fetch(APP_CONFIG.basePath+'/api/parl-count',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'total='+parls.length}).catch(()=>{});

    storageSave({
      legislaturas: allLegislaturas,
      parlamentares: allParlamentares,
      partidos: partidosRaw,
      mandatos: mandatosByLeg,
      selectedLeg,
    });

    if(!restoreHashView()) renderList();
  }catch(e){
    console.error(e);
    document.getElementById("mainContent").innerHTML=`<div class="empty">
      <p style="font-size:18px;font-weight:600;color:var(--red)">Erro ao carregar dados</p>
      <p style="font-size:14px;margin-top:8px;color:var(--muted)">${esc(e.message)}</p>
      <p style="font-size:13px;margin-top:12px;color:var(--muted)">Possíveis causas: API fora do ar, timeout na conexão ou resposta vazia.</p>
      <button onclick="location.reload()" style="margin-top:16px;padding:10px 24px;border-radius:10px;border:1.5px solid var(--accent);background:var(--accent);color:#fff;font-size:14px;font-family:inherit;cursor:pointer">Tentar novamente</button>
      <p style="font-size:12px;margin-top:12px;color:var(--muted)">Proxy: ${esc(PROXY_BASE)}</p>
    </div>`;
  }
}

// ── Extras (parl_extras) — leitura integrada nas abas ────────────────────────

// _ABAS_CAMPOS não é mais necessário no front (gerenciamento via /admin/extras)

const _ABAS_CAMPOS = {
  inicio:     [{key:'biografia',label:'Biografia',type:'textarea'}],
  materias:   [{key:'tipo',label:'Tipo (sigla)',type:'text'},{key:'numero',label:'Número',type:'text'},{key:'ano',label:'Ano',type:'number'},{key:'ementa',label:'Ementa',type:'textarea'},{key:'situacao',label:'Situação',type:'text'}],
  normas:     [{key:'tipo',label:'Tipo (sigla)',type:'text'},{key:'numero',label:'Número',type:'text'},{key:'ano',label:'Ano',type:'number'},{key:'ementa',label:'Ementa',type:'textarea'},{key:'data_norma',label:'Data',type:'date'}],
  emendas:    [{key:'numero',label:'Número',type:'text'},{key:'ano',label:'Ano',type:'number'},{key:'tipo',label:'Tipo',type:'text'},{key:'funcao',label:'Função',type:'text'},{key:'subfuncao',label:'Subfunção',type:'text'},{key:'orgao',label:'Órgão/Ministério',type:'text'},{key:'acao',label:'Ação Orçamentária',type:'text'},{key:'programa',label:'Programa',type:'text'},{key:'localidade',label:'Localidade/Destino',type:'text'},{key:'valor_dotacao',label:'Valor Dotação (R$)',type:'number'},{key:'valor_empenhado',label:'Valor Empenhado (R$)',type:'number'},{key:'valor_liquidado',label:'Valor Liquidado (R$)',type:'number'},{key:'valor_pago',label:'Valor Pago (R$)',type:'number'}],
  comissoes:  [{key:'comissao',label:'Comissão',type:'text'},{key:'data_inicio',label:'Data Início',type:'date'},{key:'data_fim',label:'Data Fim',type:'date'},{key:'cargo',label:'Cargo',type:'text'}],
  frentes:    [{key:'frente_nome',label:'Nome da Frente',type:'text'},{key:'cargo',label:'Cargo',type:'text'}],
  filiacoes:  [{key:'partido',label:'Partido (sigla)',type:'text'},{key:'data_filiacao',label:'Data Filiação',type:'date'},{key:'data_desfiliacao',label:'Data Desfiliação',type:'date'}],
  relatorias: [{key:'materia',label:'Matéria',type:'text'},{key:'comissao',label:'Comissão',type:'text'},{key:'data_designacao',label:'Data Designação',type:'date'}],
};

async function fetchExtras(parlId, aba) {
  try {
    const url = APP_CONFIG.basePath + '/api/extras?source=' + encodeURIComponent(APP_CONFIG.source) + '&sapl_id=' + parlId + '&aba=' + encodeURIComponent(aba);
    const resp = await fetch(url);
    if (!resp.ok) return [];
    return await resp.json();
  } catch(e) { return []; }
}

// ── Extras: store de dados para modal ao clicar ──────────────────────────────
const _extraDataMap = {};
let _extraDataIdx = 0;
function _storeExtra(dados, aba) { const k = ++_extraDataIdx; _extraDataMap[k] = {dados, aba}; return k; }

function openExtraDetail(k) {
  const entry = _extraDataMap[k]; if (!entry) return;
  const {dados: d, aba} = entry;
  if (aba === 'materias' || aba === 'normas') { _openExtraMateria(d, aba); return; }
  if (aba === 'relatorias')                   { _openExtraRelatoria(d);    return; }
  // Fallback genérico (outros tipos raramente precisam de modal próprio)
  const title = d.tipo ? [d.tipo, d.numero?'nº '+d.numero:'', d.ano?'/'+d.ano:''].filter(Boolean).join(' ').replace(' /','/'): (d.frente_nome||d.comissao||d.partido||'Detalhes');
  let body = '<div style="padding:20px 24px">';
  if (d.ementa) body += `<div style="border-left:3px solid var(--accent);padding:10px 14px;background:var(--accent-light);border-radius:4px;font-size:13px;line-height:1.6;color:#374151;margin-bottom:12px">${esc(d.ementa)}</div>`;
  Object.entries(d).forEach(([key, val]) => {
    if (!val || key === 'ementa' || key === 'biografia') return;
    body += `<div style="margin-bottom:8px"><span style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em">${esc(key.replace(/_/g,' '))}</span><div style="font-size:13px;color:#111827;margin-top:2px">${esc(String(val))}</div></div>`;
  });
  body += '</div>';
  showModal(title, body);
}

// Abre matéria/norma como página completa (igual ao openMateria), substituindo mainContent
function _openExtraMateria(d, aba) {
  const main = document.getElementById('mainContent');
  if (!main) return;

  const emTram = d.em_tramitacao !== undefined && d.em_tramitacao !== ''
    ? (String(d.em_tramitacao) === '1' || d.em_tramitacao === true)
    : null;
  const materiaTitle = [d.tipo, d.numero ? 'nº ' + d.numero : '', d.ano ? '/' + d.ano : ''].filter(Boolean).join(' ').replace(' /', '/');

  let h = `<div style="padding-top:28px;padding-bottom:60px">`;

  // ── Voltar (igual ao closeMateria) ──
  h += `<button class="profile-back" onclick="closeExtraMateria('${aba}')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Voltar</button>`;

  // ── Header: título + badge ──
  h += `<div class="materia-header">`;
  h += `<h1 class="materia-title">${esc(materiaTitle || (aba === 'normas' ? 'Norma' : 'Matéria'))}</h1>`;
  h += `<div style="display:flex;align-items:center;gap:8px;flex-shrink:0">`;
  if (emTram !== null) {
    h += `<span class="tag" style="background:${emTram?'var(--accent-light)':'var(--red-light)'};color:${emTram?'var(--accent)':'var(--red)'}">` + (emTram ? 'Em Tramitação' : 'Encerrada') + `</span>`;
  }
  h += `</div></div>`;

  // ── Card principal ──
  h += `<div class="materia-card">`;

  // Tipo em destaque
  if (d.tipo) {
    h += `<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--border)">`;
    h += `<span style="font-size:28px;font-weight:800;color:var(--accent);font-family:'Inter',sans-serif;line-height:1">${esc(d.tipo)}</span>`;
    h += `</div>`;
  }

  // Números, ano, data
  h += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:16px;margin-bottom:20px">`;
  if (d.numero)            h += `<div><span class="materia-label">Número</span><div style="font-size:20px;font-weight:700;color:var(--text);margin-top:4px">${esc(d.numero)}</div></div>`;
  if (d.ano)               h += `<div><span class="materia-label">Ano</span><div style="font-size:20px;font-weight:700;color:var(--text);margin-top:4px">${esc(String(d.ano))}</div></div>`;
  if (d.data_apresentacao) h += `<div><span class="materia-label">Apresentação</span><div style="font-size:14px;color:var(--text);margin-top:4px;font-weight:500">${fmtDate(d.data_apresentacao)}</div></div>`;
  if (d.data_norma)        h += `<div><span class="materia-label">Publicação</span><div style="font-size:14px;color:var(--text);margin-top:4px;font-weight:500">${fmtDate(d.data_norma)}</div></div>`;
  if (d.numero_protocolo)  h += `<div><span class="materia-label">Protocolo</span><div style="font-size:14px;color:var(--text);margin-top:4px">${esc(d.numero_protocolo)}</div></div>`;
  h += `</div>`;

  // Status: situação + órgão + regime
  if (d.situacao || d.orgao_atual || d.regime) {
    h += `<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;padding:16px;background:var(--bg);border-radius:10px;margin-bottom:20px">`;
    if (d.situacao)    h += `<div><span class="materia-label">Situação</span><div style="margin-top:6px"><span class="tag" style="background:${emTram?'var(--accent-light)':'#f3f4f6'};color:${emTram?'var(--accent)':'var(--muted)'};font-size:12px">${esc(d.situacao)}</span></div></div>`;
    if (d.orgao_atual) h += `<div><span class="materia-label">Órgão Atual</span><div style="margin-top:4px;font-size:14px;font-weight:600;color:var(--text)">${esc(d.orgao_atual)}</div></div>`;
    if (d.regime)      h += `<div><span class="materia-label">Regime</span><div style="margin-top:4px;font-size:13px;color:var(--text)">${esc(d.regime)}</div></div>`;
    h += `</div>`;
  }

  // Último despacho
  if (d.despacho_atual) {
    h += `<div style="padding:12px 16px;border-left:3px solid var(--accent);background:var(--accent-light);border-radius:0 8px 8px 0;margin-bottom:20px">`;
    h += `<span class="materia-label">Último Despacho</span>`;
    h += `<div style="margin-top:4px;font-size:13px;line-height:1.6;color:var(--text)">${esc(d.despacho_atual)}</div>`;
    h += `</div>`;
  }

  // Ementa
  h += `<div><span class="materia-label">Ementa</span>`;
  if (d.ementa) {
    h += `<div class="materia-ementa">${esc(d.ementa)}</div>`;
  } else {
    h += `<div style="margin-top:4px;color:var(--muted);font-size:13px;font-style:italic">Não disponível para esta entrada.</div>`;
  }
  h += `</div>`;

  h += `</div></div>`;

  main.innerHTML = h;
  window.scrollTo({top: 0, behavior: 'smooth'});
}

// Volta para a aba correta após abrir uma extra de matéria/norma
function closeExtraMateria(aba) {
  if (currentProfile) {
    const main = document.getElementById('mainContent');
    renderProfileShell(currentProfile).then(html => {
      main.innerHTML = html;
      switchTab(aba || 'materias');
    });
  } else { backToList(); }
}

// Modal de relatoria — idêntico ao openRelatoria mas dos dados armazenados (sem fetch)
function _openExtraRelatoria(d) {
  const materiaId = d.materia || [d.tipo, d.numero?'nº '+d.numero:'', d.ano?'/'+d.ano:''].filter(Boolean).join(' ').replace(' /','/')  || 'Relatoria';
  const emTram = d.em_tramitacao !== undefined && d.em_tramitacao !== ''
    ? (String(d.em_tramitacao) === '1' || d.em_tramitacao === true)
    : null;
  const statusTram = emTram !== null ? (emTram ? 'Em tramitação' : 'Tramitação encerrada') : '';
  const field = (l, v) => v ? `<div><span style="font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em">${l}</span><div style="font-size:.92rem;color:#111827;margin-top:2px">${v}</div></div>` : '';

  let h = `<div style="padding:22px 26px">`;

  h += `<div style="margin-bottom:16px">`;
  h += `<h2 style="font-size:1.1rem;font-weight:700;color:#111827;margin:0 0 6px">${esc(materiaId)}</h2>`;
  if (d.tipo) h += `<span style="font-size:.8rem;font-weight:600;color:var(--accent);background:var(--accent-light);padding:2px 10px;border-radius:20px;margin-right:6px">${esc(d.tipo)}</span>`;
  if (statusTram) {
    const sc = emTram ? 'color:#16a34a;background:#dcfce7' : 'color:#dc2626;background:#fee2e2';
    h += `<span style="font-size:.78rem;font-weight:600;${sc};padding:2px 10px;border-radius:20px">${esc(statusTram)}</span>`;
  }
  h += `</div>`;

  if (d.ementa) {
    h += `<div style="background:#f8faff;border-left:3px solid var(--accent);border-radius:4px;padding:10px 14px;margin-bottom:18px;font-size:.88rem;color:#374151;line-height:1.6">${esc(d.ementa)}</div>`;
  }

  h += `<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 24px">`;
  if (d.tipo)   h += field('Tipo',   esc(d.tipo));
  if (d.numero) h += field('Número', esc(d.numero));
  if (d.ano)    h += field('Ano',    esc(String(d.ano)));
  h += field('Comissão',   esc(d.comissao || '—'));
  h += field('Designação', fmtDate(d.data_designacao || ''));
  h += field('Destituição', d.data_destituicao
    ? fmtDate(d.data_destituicao)
    : '<span style="color:#16a34a;font-weight:600">Em exercício</span>');
  if (d.situacao) h += field('Situação da Relatoria', esc(d.situacao));
  h += `</div></div>`;

  showModal(`Relatoria — ${materiaId}`, h);
}

// Insere rowHtml dentro do tbody agrupado por ano (mesmo padrão de buildAutoriaGroup)
function _insertInYearGroup(tbody, colSpan, anoStr, rowHtml) {
  const rows = Array.from(tbody.rows);
  let foundYear = false, insertBeforeRow = null;
  for (const r of rows) {
    const isYearHeader = r.cells.length === 1 && r.cells[0].colSpan > 1;
    if (isYearHeader) {
      if (foundYear) { insertBeforeRow = r; break; }
      if (r.cells[0].textContent.includes('Ano: ' + anoStr)) foundYear = true;
    }
  }
  if (foundYear) {
    if (insertBeforeRow) {
      insertBeforeRow.insertAdjacentHTML('beforebegin', rowHtml);
    } else {
      tbody.insertAdjacentHTML('beforeend', rowHtml);
    }
  } else {
    tbody.insertAdjacentHTML('beforeend',
      `<tr><td colspan="${colSpan}" style="background:var(--bg);padding:7px 12px;font-weight:700;font-size:13px">Ano: ${esc(anoStr)}</td></tr>${rowHtml}`
    );
  }
}

// Injeta extras diretamente na estrutura de cada aba, sem wrapper/badge/botões.
// Os dados aparecem como se fossem do fluxo normal.
function injectExtras(el, aba, extras) {
  if (!extras.length) return;
  const fmtBRL = v => v > 0 ? 'R$ ' + Number(v).toLocaleString('pt-BR', {minimumFractionDigits:2}) : '—';

  // Helper: encontra tbody existente ou retorna null
  const findTbody = () => {
    const all = el.querySelectorAll('table tbody');
    return all.length ? all[all.length - 1] : null;
  };

  switch (aba) {

    // ── Início ──────────────────────────────────────────────────────────────
    // Normal: <h3 class="section-title">Biografia</h3><div class="bio-text">…</div>
    case 'inicio': {
      const bio = extras.map(ex => ex.dados?.biografia).filter(Boolean)[0];
      if (!bio) break;
      const html = `<h3 class="section-title">Biografia</h3><div class="bio-text">${renderBioHtml(formatBio(bio))}</div>`;
      const semEl = el.querySelector('.empty-tab');
      if (semEl) { el.innerHTML = html; }
      else { el.insertAdjacentHTML('beforeend', html); }
      break;
    }

    // ── Matérias ─────────────────────────────────────────────────────────────
    // Normal: buildAutoriaGroup → table-wrap / 3 cols (sigla | nome/desc | count)
    // Agrupa por ano e abre modal com ementa ao clicar.
    case 'materias': {
      const makeMatRow = d => {
        const k = _storeExtra(d, 'materias');
        const sigla = d.tipo || '—';
        const numPart = d.numero ? `nº ${esc(d.numero)}${d.ano ? '/' + d.ano : ''}` : (d.ano || '');
        const desc = [numPart, d.situacao ? esc(d.situacao) : ''].filter(Boolean).join(' · ');
        return `<tr class="tipo-row" onclick="openExtraDetail(${k})" style="cursor:pointer">
          <td><strong>${esc(sigla)}</strong></td>
          <td>${desc || esc(d.ementa || '—')}</td>
          <td style="text-align:right;font-weight:700;white-space:nowrap">1</td>
        </tr>`;
      };
      const tbody = findTbody();
      if (tbody) {
        extras.forEach(ex => {
          const d = ex.dados || {}; if (!d.tipo && !d.ementa) return;
          _insertInYearGroup(tbody, 3, String(d.ano || '—'), makeMatRow(d));
        });
      } else {
        const byYear = {};
        extras.forEach(ex => {
          const d = ex.dados || {}; if (!d.tipo && !d.ementa) return;
          const y = String(d.ano || '—');
          (byYear[y] = byYear[y] || []).push(d);
        });
        if (!Object.keys(byYear).length) break;
        let inner = '';
        Object.keys(byYear).sort((a, b) => b - a).forEach(ano => {
          inner += `<tr><td colspan="3" style="background:var(--bg);padding:7px 12px;font-weight:700;font-size:13px">Ano: ${esc(ano)}</td></tr>`;
          byYear[ano].forEach(d => { inner += makeMatRow(d); });
        });
        const html = `<div style="margin-bottom:28px"><div class="table-wrap"><table><tbody>${inner}</tbody></table></div></div>`;
        const semEl = el.querySelector('.empty-tab');
        if (semEl) { el.innerHTML = html; } else { el.insertAdjacentHTML('beforeend', html); }
      }
      break;
    }

    // ── Normas ───────────────────────────────────────────────────────────────
    // Normal: buildNormaGroup → table-wrap / 3 cols (sigla | nome/desc | count)
    // Agrupa por ano e abre modal com ementa ao clicar.
    case 'normas': {
      const makeNorRow = d => {
        const k = _storeExtra(d, 'normas');
        const sigla = d.tipo || '—';
        const numPart = d.numero ? `nº ${esc(d.numero)}${d.ano ? '/' + d.ano : ''}` : (d.ano || '');
        const desc = [numPart, d.data_norma ? fmtDate(d.data_norma) : ''].filter(Boolean).join(' · ');
        return `<tr class="tipo-row" onclick="openExtraDetail(${k})" style="cursor:pointer">
          <td><strong>${esc(sigla)}</strong></td>
          <td>${desc || esc(d.ementa || '—')}</td>
          <td style="text-align:right;font-weight:700;white-space:nowrap">1</td>
        </tr>`;
      };
      const tbody = findTbody();
      if (tbody) {
        extras.forEach(ex => {
          const d = ex.dados || {}; if (!d.tipo && !d.ementa) return;
          _insertInYearGroup(tbody, 3, String(d.ano || '—'), makeNorRow(d));
        });
      } else {
        const byYear = {};
        extras.forEach(ex => {
          const d = ex.dados || {}; if (!d.tipo && !d.ementa) return;
          const y = String(d.ano || '—');
          (byYear[y] = byYear[y] || []).push(d);
        });
        if (!Object.keys(byYear).length) break;
        let inner = '';
        Object.keys(byYear).sort((a, b) => b - a).forEach(ano => {
          inner += `<tr><td colspan="3" style="background:var(--bg);padding:7px 12px;font-weight:700;font-size:13px">Ano: ${esc(ano)}</td></tr>`;
          byYear[ano].forEach(d => { inner += makeNorRow(d); });
        });
        const html = `<div style="margin-bottom:28px"><div class="table-wrap"><table><tbody>${inner}</tbody></table></div></div>`;
        const semEl = el.querySelector('.empty-tab');
        if (semEl) { el.innerHTML = html; } else { el.insertAdjacentHTML('beforeend', html); }
      }
      break;
    }

    // ── Emendas ──────────────────────────────────────────────────────────────
    case 'emendas': {
      const fmtE = v => (v&&v>0) ? 'R$ '+Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2}) : '—';
      const makeRow = d => {
        const sc = (d.valor_pago>0)?'#16a34a':(d.valor_liquidado>0)?'#d97706':(d.valor_empenhado>0)?'#2563eb':'#9ca3af';
        return `<tr>
          <td style="font-weight:600;white-space:nowrap">${esc(d.numero||'—')}</td>
          <td style="font-size:12px">${esc(d.orgao||'—')}</td>
          <td style="font-size:12px">${esc(d.subfuncao||d.funcao||'—')}</td>
          <td style="font-size:12px">${esc(d.localidade||'—')}</td>
          <td style="text-align:right;font-weight:500">${fmtE(d.valor_dotacao)}</td>
          <td style="text-align:right;color:#2563eb">${fmtE(d.valor_empenhado)}</td>
          <td style="text-align:right;color:#d97706">${fmtE(d.valor_liquidado)}</td>
          <td style="text-align:right;font-weight:600;color:${sc}">${fmtE(d.valor_pago)}</td>
        </tr>`;
      };
      const tbody = findTbody();
      if (tbody) {
        extras.forEach(ex => tbody.insertAdjacentHTML('beforeend', makeRow(ex.dados || {})));
      } else {
        let rows = extras.map(ex => makeRow(ex.dados || {})).join('');
        const thead = '<tr><th>Nº</th><th>Órgão</th><th>Subfunção</th><th>Localidade</th><th style="text-align:right">Dotação</th><th style="text-align:right">Empenhado</th><th style="text-align:right">Liquidado</th><th style="text-align:right">Pago</th></tr>';
        const card = `<div class="materia-card"><div class="table-wrap"><table><thead>${thead}</thead><tbody>${rows}</tbody></table></div></div>`;
        const semEl = el.querySelector('.empty-tab');
        if (semEl) { semEl.replaceWith(document.createRange().createContextualFragment(card)); }
        else { el.insertAdjacentHTML('beforeend', card); }
      }
      break;
    }

    // ── Comissões ────────────────────────────────────────────────────────────
    // Normal: h3 section-title + table-wrap / 3 cols (Comissão|Cargo|Período)
    case 'comissoes': {
      const makeRow = d => {
        const ini = fmtDate(d.data_inicio)||'', fim = fmtDate(d.data_fim)||'';
        const periodo = ini && fim ? `${ini} a ${fim}` : ini || fim || '—';
        return `<tr>
          <td style="font-weight:500"><span style="color:var(--accent)">${esc(d.comissao||'—')}</span></td>
          <td style="color:#374151">${esc(d.cargo||'—')}</td>
          <td style="color:#6b7280;white-space:nowrap">${periodo}</td>
        </tr>`;
      };
      const tbody = findTbody();
      if (tbody) {
        extras.forEach(ex => tbody.insertAdjacentHTML('beforeend', makeRow(ex.dados || {})));
      } else {
        let rows = extras.map(ex => makeRow(ex.dados || {})).join('');
        const html = `<h3 class="section-title">Participações em Comissão (${extras.length})</h3>`
          + `<div class="table-wrap"><table><thead><tr><th>Comissão</th><th>Cargo</th><th>Período de participação</th></tr></thead><tbody>${rows}</tbody></table></div>`;
        const semEl = el.querySelector('.empty-tab');
        if (semEl) { el.innerHTML = html; } else { el.insertAdjacentHTML('beforeend', html); }
      }
      break;
    }

    // ── Frentes ──────────────────────────────────────────────────────────────
    // Normal: h3 + total div + table-wrap / 4 cols (Frente|Cargo|Data Entrada|Data Saída)
    case 'frentes': {
      const makeRow = d => `<tr>
        <td style="font-weight:600">${esc(d.frente_nome||'—')}</td>
        <td style="color:#111827">${esc(d.cargo||'—')}</td>
        <td style="color:#111827">—</td>
        <td style="color:#111827">—</td>
      </tr>`;
      const tbody = findTbody();
      if (tbody) {
        extras.forEach(ex => tbody.insertAdjacentHTML('beforeend', makeRow(ex.dados || {})));
      } else {
        let rows = extras.map(ex => makeRow(ex.dados || {})).join('');
        const html = `<h3 class="section-title">Frentes Parlamentares</h3>`
          + `<div style="margin-bottom:16px;font-size:14px;color:var(--muted)">Total: <strong style="color:#111827">${extras.length}</strong></div>`
          + `<div class="table-wrap"><table><thead><tr><th>Frente</th><th>Cargo</th><th>Data de Entrada</th><th>Data de Saída</th></tr></thead><tbody>${rows}</tbody></table></div>`;
        const semEl = el.querySelector('.empty-tab');
        if (semEl) { el.innerHTML = html; } else { el.insertAdjacentHTML('beforeend', html); }
      }
      break;
    }

    // ── Filiações ────────────────────────────────────────────────────────────
    // Normal: h3 section-title (N) + table-wrap / 4 cols (Partido|Data Fil|Data Desfil|Status)
    case 'filiacoes': {
      const makeRow = d => {
        const isActive = !d.data_desfiliacao;
        const pc = partyColor(d.partido || '');
        return `<tr>
          <td><span class="card-party" style="background:${pc[0]};color:${pc[1]};margin-right:8px">${esc(d.partido||'?')}</span></td>
          <td style="color:#111827">${fmtDate(d.data_filiacao)||'—'}</td>
          <td style="color:#111827">${fmtDate(d.data_desfiliacao)||'—'}</td>
          <td><span class="td-titular ${isActive?'yes':'no'}">${isActive?'Atual':'Encerrada'}</span></td>
        </tr>`;
      };
      const tbody = findTbody();
      if (tbody) {
        extras.forEach(ex => tbody.insertAdjacentHTML('beforeend', makeRow(ex.dados || {})));
      } else {
        let rows = extras.map(ex => makeRow(ex.dados || {})).join('');
        const html = `<h3 class="section-title">Filiações Partidárias (${extras.length})</h3>`
          + `<div class="table-wrap"><table><thead><tr><th>Partido</th><th>Data Filiação</th><th>Data Desfiliação</th><th>Status</th></tr></thead><tbody>${rows}</tbody></table></div>`;
        const semEl = el.querySelector('.empty-tab');
        if (semEl) { el.innerHTML = html; } else { el.insertAdjacentHTML('beforeend', html); }
      }
      break;
    }

    // ── Relatorias ───────────────────────────────────────────────────────────
    // Normal: h3 (N) + <div id="tab-relatorias"> + table-wrap / 4 cols — linhas clicáveis
    case 'relatorias': {
      const makeRow = d => {
        const k = _storeExtra(d, 'relatorias');
        const destStr = d.data_destituicao ? fmtDate(d.data_destituicao) : '<span style="color:#16a34a;font-size:.8em">Em exercício</span>';
        return `<tr style="cursor:pointer" onclick="openExtraDetail(${k})" title="Clique para ver detalhes">
          <td style="font-weight:500;color:var(--accent)">${esc(d.materia||'—')}</td>
          <td style="color:#111827">${esc(d.comissao||'—')}</td>
          <td style="color:#111827">${fmtDate(d.data_designacao)||'—'}</td>
          <td>${destStr}</td>
        </tr>`;
      };
      const tbody = el.querySelector('#tab-relatorias table tbody') || findTbody();
      if (tbody) {
        extras.forEach(ex => tbody.insertAdjacentHTML('beforeend', makeRow(ex.dados || {})));
      } else {
        let rows = extras.map(ex => makeRow(ex.dados || {})).join('');
        const html = `<h3 class="section-title">Relatorias (${extras.length})</h3>`
          + `<div id="tab-relatorias"><div class="table-wrap"><table><thead><tr><th>Matéria</th><th>Comissão</th><th>Designação</th><th>Destituição</th></tr></thead><tbody>${rows}</tbody></table></div></div>`;
        const semEl = el.querySelector('.empty-tab');
        if (semEl) { el.innerHTML = html; } else { el.insertAdjacentHTML('beforeend', html); }
      }
      break;
    }
  }
}

init();
