/**
 * Parlamentares · app.js v4
 * Grupos por tipo, dashboard filtros, agente IA, bio formatada
 */

const PROXY_BASE = APP_CONFIG.proxyBase;
const API_BASE   = APP_CONFIG.saplBaseUrl;

let allParlamentares=[], allLegislaturas=[], allPartidos={}, allPartidosSigla={};
let mandatosByLeg=[], selectedLeg="", search="";
let onlyActive=true, onlyTitular=false;
let currentProfile=null, activeTab="inicio";
let tipoNomes={};       // materia: sigla → nome completo
let tipoNomesRev={};    // materia: nome completo → sigla
let normaTipoNomes={};  // norma: sigla → nome completo
let normaTipoNomesRev={}; // norma: nome completo → sigla

let tabDataCache = {};

// ── LocalStorage cache (TTL 24h) ──
const STORAGE_TTL = 86_400_000;
const CACHE_VER   = APP_CONFIG.cacheVer || 'v4';
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
    h+=`<div class="card" onclick="openProfile(${p.id})" onmouseenter="prefetchCard(${p.id})">`;
    h+=s?`<img class="card-img" src="${esc(s)}" alt="${n}" loading="lazy" onerror="this.outerHTML='<div class=card-avatar>${ini}</div>'">`:`<div class="card-avatar">${ini}</div>`;
    h+=`<div class="card-body"><div class="card-name">${n}<span class="dot ${at?'on':'off'}"></span></div>`;
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
  if(path.startsWith('http')){
    try{ path=new URL(path).pathname; }catch(e){ return null; }
  }
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
  pageItems.forEach(item => { h += renderRowFn(item); });
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
  if(selectedLeg&&mandatosByLeg.length>0){
    const pids=new Set(mandatosByLeg.map(m=>m.parlamentar));
    const tids=new Set(mandatosByLeg.filter(m=>m.titular).map(m=>m.parlamentar));
    list=allParlamentares.filter(p=>pids.has(p.id)).map(p=>({...p,_tit:tids.has(p.id)}));
    if(onlyTitular)list=list.filter(p=>p._tit);
  }else{list=[...allParlamentares]}
  if(onlyActive)list=list.filter(p=>!!p.ativo);
  if(search.trim()){const s=search.toLowerCase();list=list.filter(p=>(p.nome_parlamentar||"").toLowerCase().includes(s)||(p.nome_completo||"").toLowerCase().includes(s))}
  list.sort((a,b)=>(a.nome_parlamentar||"").localeCompare(b.nome_parlamentar||""));
  return list;
}

function renderGrid() {
  const list = getFilteredList();
  const cl   = allLegislaturas.find(l=>String(l.id)===selectedLeg);
  const li   = cl?` na ${cl.numero}ª Legislatura (${new Date(cl.data_inicio).getFullYear()}–${new Date(cl.data_fim).getFullYear()})`:'';
  const statsEl = document.getElementById("listStats");
  const gridEl  = document.getElementById("listGrid");
  if(!statsEl||!gridEl) return renderList();
  statsEl.innerHTML=`<span class="stats-badge">${list.length}</span> parlamentar${list.length!==1?'es':''} encontrado${list.length!==1?'s':''}${li}`;
  gridEl.innerHTML=buildCardGrid(list);
}

function renderList() {
  const main=document.getElementById("mainContent");
  const list=getFilteredList();
  const cl=allLegislaturas.find(l=>String(l.id)===selectedLeg);

  let h='<div class="controls"><select onchange="onChangeLeg(this.value)"><option value="">Todas as Legislaturas</option>';
  allLegislaturas.forEach(l=>{h+=`<option value="${l.id}"${String(l.id)===selectedLeg?" selected":""}>${l.numero}ª (${new Date(l.data_inicio).getFullYear()} – ${new Date(l.data_fim).getFullYear()})${l===allLegislaturas[0]?" (Atual)":""}</option>`});
  h+='</select><div class="search-wrap"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>';
  h+=`<input type="text" id="searchInput" placeholder="Pesquisar parlamentar..." value="${esc(search)}" oninput="onSearch(this.value)"></div>`;
  const isCamara = APP_CONFIG.source === 'camara_federal';
  if(!isCamara){
    h+=`<div class="toggle-group"><button class="toggle-btn${onlyActive?' active':''}" onclick="toggleActive()">Apenas Ativos</button><button class="toggle-btn${onlyTitular?' active':''}" onclick="toggleTitular()">Apenas Titulares</button></div>`;
  }
  h+=`<button id="btn-atualizar" onclick="atualizarDados()" title="Limpar cache e recarregar" style="margin-left:auto;display:flex;align-items:center;gap:6px;padding:7px 13px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;white-space:nowrap"><i class="ph ph-arrows-clockwise"></i> Atualizar</button>`;
  h+=`</div>`;
  const li=cl?` na ${cl.numero}ª Legislatura (${new Date(cl.data_inicio).getFullYear()}–${new Date(cl.data_fim).getFullYear()})`:'';
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
  {id:'materias',  label:'Matérias',             icon:'<i class="ph ph-file-text"></i>'},
  {id:'normas',    label:'Normas',               icon:'<i class="ph ph-scroll"></i>'},
  {id:'filiacoes', label:'Filiações Partidárias',icon:'<i class="ph ph-flag"></i>'},
  {id:'comissoes', label:'Comissões',            icon:'<i class="ph ph-users"></i>'},
  {id:'relatorias',label:'Relatorias',           icon:'<i class="ph ph-clipboard-text"></i>'},
  {id:'frentes',   label:'Frentes',              icon:'<i class="ph ph-handshake"></i>'},
  {id:'dashboard', label:'Dashboard',            icon:'<i class="ph ph-chart-bar"></i>'},
  {id:'agente',    label:'Agente IA',            icon:'<i class="ph ph-robot"></i>'},
];

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
  if(p.endereco_web) h+=`<div class="detail-row"><span class="detail-label">Homepage:</span><span class="detail-value"><a href="${esc(p.endereco_web)}" target="_blank" style="color:var(--accent)">${esc(p.endereco_web)}</a></span></div>`;
  if(p.numero_gab_parlamentar) h+=`<div class="detail-row"><span class="detail-label">Nº Gabinete:</span><span class="detail-value">${esc(p.numero_gab_parlamentar)}</span></div>`;
  if(p.profissao) h+=`<div class="detail-row"><span class="detail-label">Profissão:</span><span class="detail-value">${esc(p.profissao)}</span></div>`;
  h+=`<div class="detail-row"><span class="detail-label">Situação:</span><span class="detail-value"><span class="tag" style="background:${at?'var(--accent-light)':'var(--red-light)'};color:${at?'var(--accent)':'var(--red)'}"><span class="dot ${at?'on':'off'}"></span> ${at?'Ativo':'Inativo'}</span></span></div>`;
  h+='</div></div></div>';
  h+='<div class="tabs-nav">';
  TABS.forEach(t=>{h+=`<button class="tab-btn${activeTab===t.id?' active':''}" onclick="switchTab('${t.id}')">${t.label}</button>`});
  h+='</div>';
  h+='<div id="tabContent"><div class="tab-loader"><div class="spinner"></div></div></div>';
  h+='</div>';
  return h;
}

async function switchTab(tabId) {
  activeTab=tabId;
  document.querySelectorAll('.tab-btn').forEach((btn,i)=>{btn.classList.toggle('active',TABS[i].id===tabId)});
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
    else if(tabId==='filiacoes') html=await renderTabFiliacoes(p);
    else if(tabId==='comissoes') html=await renderTabComissoes(p);
    else if(tabId==='relatorias')html=await renderTabRelatorias(p);
    else if(tabId==='frentes')   html=await renderTabFrentes(p);
    else if(tabId==='agente')    html=await renderTabAgente(p);
    el.innerHTML=html;
    if(tabId==='dashboard') setTimeout(initDashboardCharts,0);
    if(tabId==='agente')    setTimeout(initAgenteEvents,0);
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
let dashboardAllMaterias = null;
let dashboardAllNormas   = null;
let dashboardChartInstances = {};

async function renderTabDashboard(p) {
  const autorData = await getAutorData(p);
  if(!autorData) return `<div class="empty-tab"><i class="ph ph-user-x" style="font-size:28px;color:var(--muted);display:block;margin-bottom:10px"></i><p style="font-weight:600;color:var(--text);margin-bottom:4px">Autor não localizado na API</p><p style="font-size:12px;color:var(--muted)">O parlamentar não foi encontrado no sistema de autoria do SAPL. Isso pode ocorrer quando o nome cadastrado difere da base legislativa.</p></div>`;

  // Carrega tipos de matéria e norma para resolução de siglas
  const [allMaterias, allNormasData] = await Promise.all([
    getCached(p.id,'all_materias',()=>fetchAllPages(`/materia/autoria/?autor=${autorData.id}&o=-id`)),
    getCached(p.id,'normas',()=>fetchAllPages(`/norma/autorianorma/?autor=${autorData.id}`)),
    !Object.keys(tipoNomes).length
      ? getCached('global','tipomateria',()=>fetchAllPages('/materia/tipomateria/')).then(ts=>{ ts.forEach(t=>{ if(t.sigla){ const s=t.sigla.toUpperCase(),d=(t.descricao||t.sigla).toUpperCase(); tipoNomes[s]=d; tipoNomesRev[d]=s; } }); }).catch(()=>{})
      : Promise.resolve(),
    !Object.keys(normaTipoNomes).length
      ? getCached('global','tiponorma',()=>fetchAllPages('/norma/tiponormajuridica/')).then(ts=>{ ts.forEach(t=>{ if(t.sigla){ const s=t.sigla.toUpperCase(),d=(t.descricao||t.sigla).toUpperCase(); normaTipoNomes[s]=d; normaTipoNomesRev[d]=s; } }); }).catch(()=>{})
      : Promise.resolve(),
  ]);

  dashboardAllMaterias = allMaterias;
  dashboardAllNormas   = allNormasData;

  const totalMaterias  = allMaterias.length;
  const totalNormas    = allNormasData.length;
  const totalPrimeiro  = allMaterias.filter(m=>m.primeiro_autor).length;
  const totalCoautoria = totalMaterias - totalPrimeiro;

  // Anos disponíveis
  const allYears = new Set();
  allMaterias.forEach(m=>{ const y=extractMateriaInfo(m).ano; if(y&&y!=='—') allYears.add(y); });
  allNormasData.forEach(n=>{ const y=extractNormaInfo(n).ano; if(y&&y!=='—') allYears.add(y); });
  const yearsArr = [...allYears].sort();

  // Tipos únicos com sigla para exibição no select
  const matTiposMap  = {};  // tipoRaw → sigla label
  allMaterias.forEach(m=>{ const {tipoRaw,sigla}=extractMateriaInfo(m); if(!matTiposMap[tipoRaw]) matTiposMap[tipoRaw]=sigla; });
  const normTiposMap = {};
  allNormasData.forEach(n=>{ const {tipoRaw,nome}=extractNormaInfo(n); if(!normTiposMap[tipoRaw]) normTiposMap[tipoRaw]=nome; });

  const matTiposRaw  = Object.keys(matTiposMap).sort();
  const normTiposRaw = Object.keys(normTiposMap).sort();

  let h='<div class="dashboard-grid">';

  // KPIs
  h+='<div class="kpi-row">';
  h+=`<div class="kpi-card"><div class="kpi-value">${totalMaterias.toLocaleString('pt-BR')}</div><div class="kpi-label">Matérias</div></div>`;
  h+=`<div class="kpi-card"><div class="kpi-value">${totalNormas.toLocaleString('pt-BR')}</div><div class="kpi-label">Normas</div></div>`;
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
    h+='<select id="filterTipoMateria" onchange="applyDashFilters()" class="dash-filter-select"><option value="">Tipo de matéria</option>';
    matTiposRaw.forEach(raw=>{const sigla=matTiposMap[raw]; h+=`<option value="${esc(raw)}" title="${esc(sigla)}">${esc(truncOpt(sigla))}</option>`});
    h+='</select>';
  }
  if(normTiposRaw.length>1){
    h+='<select id="filterTipoNorma" onchange="applyDashFilters()" class="dash-filter-select"><option value="">Tipo de norma</option>';
    normTiposRaw.forEach(raw=>{const nome=normTiposMap[raw]; h+=`<option value="${esc(raw)}" title="${esc(nome)}">${esc(truncOpt(nome))}</option>`});
    h+='</select>';
  }
  h+='</div>';

  // Gráficos 2×2
  h+='<div class="chart-row" style="grid-template-columns:1fr 1fr">';
  h+=`<div class="chart-box"><h3 class="section-title" style="font-size:14px;margin-bottom:12px">Matérias por Ano</h3><div style="position:relative;height:200px"><canvas id="chartProdAnual"></canvas></div></div>`;
  h+=`<div class="chart-box"><h3 class="section-title" style="font-size:14px;margin-bottom:12px">Matérias por Tipo</h3><div style="position:relative;height:200px"><canvas id="chartMateriasTipo"></canvas></div></div>`;
  h+=`<div class="chart-box"><h3 class="section-title" style="font-size:14px;margin-bottom:12px">Normas por Ano</h3><div style="position:relative;height:200px"><canvas id="chartNormasAnual"></canvas></div></div>`;
  h+=`<div class="chart-box"><h3 class="section-title" style="font-size:14px;margin-bottom:12px">Normas por Tipo</h3><div style="position:relative;height:200px"><canvas id="chartNormasTipo"></canvas></div></div>`;
  h+='</div>';

  h+='</div>';
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
if(typeof Chart!=='undefined') Chart.register(barValuePlugin);

function redrawDashCharts(materias, normas) {
  Object.values(dashboardChartInstances).forEach(c=>{try{c.destroy()}catch(e){}});
  dashboardChartInstances={};

  const COLORS=['#1A6B4F','#C9A84C','#3B82F6','#EC4899','#8B5CF6','#F59E0B','#10B981','#EF4444','#0EA5E9','#A855F7'];
  const font={family:"'Inter',sans-serif",size:11};
  const emptyMsg='<div style="display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);font-size:12px;text-align:center;padding:8px">Nenhum dado disponível<br>para o filtro selecionado</div>';

  function mountChart(id,config){
    const canvas=document.getElementById(id);
    if(!canvas) return;
    dashboardChartInstances[id]=new Chart(canvas,config);
  }
  function showEmpty(id){
    const el=document.getElementById(id);
    if(el) el.closest('[style*="height:200px"]').innerHTML=emptyMsg;
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
}

function semDados(msg){
  return `<div class="empty-tab"><i class="ph ph-magnifying-glass" style="font-size:28px;color:var(--muted);display:block;margin-bottom:10px"></i><p style="font-weight:600;color:var(--text);margin-bottom:4px">${msg}</p><p style="font-size:12px;color:var(--muted)">Nenhum registro encontrado para este parlamentar nesta fonte.</p></div>`;
}

function semModulo(msg){
  return `<div class="empty-tab"><i class="ph ph-prohibit" style="font-size:28px;color:#93c5fd;display:block;margin-bottom:10px"></i><p style="font-weight:600;color:var(--text);margin-bottom:4px">${msg}</p><p style="font-size:12px;color:var(--muted)">Esta fonte de dados não registra este tipo de informação.</p></div>`;
}

function getSourceCap() {
  return getCached('global', 'sourcecap', async () => {
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
  return semDados('Biografia não encontrada');
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
function extractMateriaInfo(a) {
  const raw = stripAutoria(a.__str__||'');
  const dash = raw.indexOf(' - ');
  const materiaStr = dash >= 0 ? raw.slice(dash+3) : raw;
  const m = materiaStr.match(/^([A-Za-záàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇ][A-Za-záàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇ\s\.]*?)\s+(?:n[ºo°]|nº|\d)/i);
  const tipoRaw = m ? m[1].trim().toUpperCase() : 'Outros';
  const sigla   = tipoNomesRev[tipoRaw] || tipoRaw;
  const ano     = extractYear(materiaStr) || '—';
  // tipoRaw é a chave estável (independe do momento em que tipoNomesRev é carregado)
  return {sigla, tipoRaw, tipoNome: tipoNomes[sigla]||tipoRaw, ano, label: materiaStr||`Matéria #${a.materia}`};
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
  let h=`<h3 class="section-title">Matérias Legislativas</h3>`;
  h+=`<div style="font-size:13px;color:var(--muted);margin-bottom:20px">
    <strong style="color:var(--text)">${total.toLocaleString('pt-BR')}</strong> matéria${total!==1?'s':''}
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
    const sigla=tipoNomesRev[tipoRaw]||tipoRaw;
    const nome=tipoNomes[sigla]||tipoRaw;
    const autorLabel=isPrimeiro?'Primeiro Autor':'Co-Autor';
    let h=`<button onclick="voltarParaTipos()" style="display:inline-flex;align-items:center;gap:6px;margin-bottom:20px;padding:7px 14px;border-radius:8px;border:1.5px solid var(--border);background:var(--surface);color:var(--muted);font-size:13px;font-weight:600;font-family:inherit;cursor:pointer"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Voltar</button>`;
    h+=`<h3 class="section-title" style="margin-bottom:4px">${esc(sigla)} <span style="color:var(--muted);font-weight:400">— ${esc(nome)}</span></h3>`;
    h+=`<div style="font-size:13px;color:var(--muted);margin-bottom:16px">${esc(String(ano))} · ${esc(autorLabel)} · ${filtrado.length.toLocaleString('pt-BR')} matéria${filtrado.length!==1?'s':''}</div>`;
    h+='<div class="table-wrap"><table><thead><tr><th>Matéria</th><th>Autoria</th></tr></thead><tbody>';
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
    const [m, tramitacoes, autores, docs] = await Promise.all([
      fetchWithRetry(proxyUrl(`/materia/materialegislativa/${materiaId}/`)),
      fetchAllPages(`/materia/tramitacao/?materia=${materiaId}`).catch(()=>[]),
      fetchAllPages(`/materia/autoria/?materia=${materiaId}`).catch(()=>[]),
      fetchAllPages(`/materia/documentosacessorio/?materia=${materiaId}`).catch(()=>[]),
    ]);
    if(!m||!m.id) throw new Error("Matéria não encontrada");

    tramitacoes.sort((a,b)=>(b.data_tramitacao||'').localeCompare(a.data_tramitacao||''));

    let h='<div style="padding-top:28px;padding-bottom:60px">';
    h+='<button class="profile-back" onclick="closeMateria()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Voltar</button>';

    h+='<div class="materia-header">';
    h+=`<h1 class="materia-title">${esc(m.__str__||'Matéria #'+m.id)}</h1>`;
    if(m.em_tramitacao!==undefined){
      h+=`<span class="tag" style="background:${m.em_tramitacao?'var(--accent-light)':'var(--red-light)'};color:${m.em_tramitacao?'var(--accent)':'var(--red)'}">${m.em_tramitacao?'Em Tramitação':'Tramitação Encerrada'}</span>`;
    }
    h+='</div>';

    h+='<div class="materia-card">';
    h+='<h3 class="section-title">Identificação Básica</h3>';
    h+='<div class="materia-grid">';
    if(m.tipo)              h+=`<div class="materia-field"><span class="materia-label">Tipo de Matéria</span><div class="materia-value">${esc(m.__str__?.split(' nº')[0]||'Tipo '+m.tipo)}</div></div>`;
    if(m.ano)               h+=`<div class="materia-field"><span class="materia-label">Ano</span><div class="materia-value">${m.ano}</div></div>`;
    if(m.numero)            h+=`<div class="materia-field"><span class="materia-label">Número</span><div class="materia-value">${m.numero}</div></div>`;
    if(m.data_apresentacao) h+=`<div class="materia-field"><span class="materia-label">Data de Apresentação</span><div class="materia-value">${fmtDate(m.data_apresentacao)}</div></div>`;
    if(m.numero_protocolo)  h+=`<div class="materia-field"><span class="materia-label">Nº Protocolo</span><div class="materia-value">${m.numero_protocolo}</div></div>`;
    if(m.tipo_apresentacao) h+=`<div class="materia-field"><span class="materia-label">Tipo Apresentação</span><div class="materia-value">${m.tipo_apresentacao==='E'?'Escrita':m.tipo_apresentacao}</div></div>`;
    if(m.regime_tramitacao) h+=`<div class="materia-field"><span class="materia-label">Regime</span><div class="materia-value">${({1:'Normal',2:'Urgência'}[m.regime_tramitacao]??'Regime '+m.regime_tramitacao)}</div></div>`;
    h+='</div>';
    if(m.ementa){
      h+='<div style="margin-top:20px"><span class="materia-label">Ementa</span>';
      h+=`<div class="materia-ementa">${esc(m.ementa)}</div></div>`;
    }
    if(m.texto_original){
      const textoUrl=m.texto_original.startsWith('http')?m.texto_original:API_BASE+m.texto_original;
      h+=`<div style="margin-top:16px"><a href="${esc(textoUrl)}" target="_blank" class="btn-pdf"><i class="ph ph-file-pdf"></i> Abrir documento</a></div>`;
    }
    h+='</div>';

    if(autores.length>0){
      h+='<div class="materia-card">';
      h+=`<h3 class="section-title">Autores (${autores.length})</h3>`;
      h+='<div class="table-wrap"><table><thead><tr><th>Autor</th><th>1º Autor</th></tr></thead><tbody>';
      autores.forEach(a=>{
        const nome=(a.__str__||'').replace(/^Autoria:.*?-\s*/,'').trim()||a.__str__||'—';
        h+=`<tr><td style="font-weight:500;color:#111827">${esc(nome)}</td><td><span class="td-titular ${a.primeiro_autor?'yes':'no'}">${a.primeiro_autor?'Sim':'Não'}</span></td></tr>`;
      });
      h+='</tbody></table></div></div>';
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
        h+=`<div class="tram-item${i===0?' tram-latest':''}"><div class="tram-dot"></div><div class="tram-content">`;
        h+=`<div class="tram-date">${fmtDate(t.data_tramitacao)}</div>`;
        if(statusStr) h+=`<div class="tram-status">${esc(statusStr)}</div>`;
        if(destStr)   h+=`<div class="tram-dest">Destino: ${esc(destStr)}</div>`;
        if(textoStr)  h+=`<div class="tram-texto">${esc(textoStr)}</div>`;
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
  const normaStr = dash >= 0 ? raw.slice(dash+3) : raw;
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

  const [normas, capN]=await Promise.all([
    getCached(p.id,'normas',()=>fetchAllPages(`/norma/autorianorma/?autor=${autorData.id}`)),
    getSourceCap(),
  ]);
  if(!normas.length) return capN.normas ? semDados('Nenhuma norma jurídica encontrada para este parlamentar') : semModulo('Esta fonte não utiliza Normas Jurídicas');

  const primeiros=normas.filter(n=>n.primeiro_autor);
  const coautores=normas.filter(n=>!n.primeiro_autor);

  let h=`<h3 class="section-title">Normas Jurídicas</h3>`;
  h+=`<div style="font-size:13px;color:var(--muted);margin-bottom:20px">
    <strong style="color:var(--text)">${normas.length.toLocaleString('pt-BR')}</strong> norma${normas.length!==1?'s':''}
  </div>`;
  if(primeiros.length) h+=buildNormaGroup('Primeiro Autor', primeiros);
  if(coautores.length) h+=buildNormaGroup('Co-Autor', coautores);
  return h;
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
    h+=`<h1 class="materia-title">${esc(n.__str__||'Norma #'+n.id)}</h1>`;

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

  let h=`<h3 class="section-title">Comissões (${parts.length})</h3>`;
  const thead='<tr><th>Título da Comissão</th><th>Cargo</th><th>Titular</th><th>Início</th><th>Encerramento</th></tr>';
  h+=paginateTable(parts,10,tablePages['pg-comissoes']||1,c=>{
    const str=c.__str__||'';
    // Tenta separar "Comissão Nome - Cargo" ou usa o __str__ inteiro como título
    const sepIdx=str.indexOf(' - ');
    const comissaoNome = sepIdx>0 ? str.slice(0,sepIdx).trim() : str;
    const cargo        = sepIdx>0 ? str.slice(sepIdx+3).trim()  : '';
    return `<tr>
      <td style="font-weight:600;color:#111827">${esc(comissaoNome)}</td>
      <td style="color:#111827">${cargo?esc(cargo):'—'}</td>
      <td><span class="td-titular ${c.titular?'yes':'no'}">${c.titular?'Titular':'Suplente'}</span></td>
      <td style="color:#111827">${fmtDate(c.data_designacao)}</td>
      <td style="color:#111827">${fmtDate(c.data_desligamento)}</td>
    </tr>`;
  },thead,'pg-comissoes');
  return h;
}

// ── Tab: Relatorias ──
async function renderTabRelatorias(p) {
  const [rels, capR]=await Promise.all([
    getCached(p.id,'relatorias',()=>fetchAllPages(`/materia/relatoria/?parlamentar=${p.id}`)),
    getSourceCap(),
  ]);
  rels.sort((a,b)=>(b.data_designacao_relator||"").localeCompare(a.data_designacao_relator||""));
  if(!rels.length) return capR.relatorias ? semDados('Nenhuma relatoria encontrada para este parlamentar') : semModulo('Esta fonte não utiliza Relatorias');

  let h=`<h3 class="section-title">Relatorias (${rels.length})</h3>`;
  const thead='<tr><th>Matéria / Título da Relatoria</th><th>Comissão</th><th>Designação</th><th>Destituição</th></tr>';
  h+=paginateTable(rels,10,tablePages['pg-relatorias']||1,r=>{
    const title=r.__str__||('Relatoria #'+r.id);
    const comissaoInfo=r.comissao?(typeof r.comissao==='object'?(r.comissao.__str__||r.comissao.nome||'—'):'—'):'—';
    return `<tr>
      <td style="font-weight:500">${r.materia
        ?`<a href="javascript:void(0)" onclick="openMateria(${r.materia})" style="color:var(--accent)">${esc(title)}</a>`
        :esc(title)
      }</td>
      <td style="color:#111827">${esc(comissaoInfo)}</td>
      <td style="color:#111827">${fmtDate(r.data_designacao_relator)}</td>
      <td style="color:#111827">${fmtDate(r.data_destituicao_relator)}</td>
    </tr>`;
  },thead,'pg-relatorias');
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

async function renderTabAgente(p) {
  // Pré-carrega dados para contexto
  const autorData = await getAutorData(p);
  const [allMat, allNorm] = await Promise.all([
    autorData ? getCached(p.id,'all_materias',()=>fetchAllPages(`/materia/autoria/?autor=${autorData.id}&o=-id`)) : Promise.resolve([]),
    autorData ? getCached(p.id,'normas',()=>fetchAllPages(`/norma/autorianorma/?autor=${autorData.id}`)) : Promise.resolve([]),
  ]);

  // Monta contexto resumido para a IA
  const nomeParlamentar = p.nome_parlamentar||p.nome_completo||'';
  const tiposMat  = Object.entries(groupByTipo(allMat)).sort((a,b)=>b[1]-a[1]);
  const tiposNorm = Object.entries(groupByTipo(allNorm)).sort((a,b)=>b[1]-a[1]);
  const anosMatList  = Object.entries(groupByYear(allMat)).sort((a,b)=>b[0].localeCompare(a[0])).slice(0,10);
  const anosNormList = Object.entries(groupByYear(allNorm)).sort((a,b)=>b[0].localeCompare(a[0])).slice(0,10);

  let ctx = `Parlamentar: ${nomeParlamentar}\n`;
  ctx += `Total de matérias: ${allMat.length}\n`;
  ctx += `Total de normas: ${allNorm.length}\n\n`;
  if(tiposMat.length) ctx += `Matérias por tipo:\n${tiposMat.map(([t,n])=>`  ${t}: ${n}`).join('\n')}\n\n`;
  if(tiposNorm.length) ctx += `Normas por tipo:\n${tiposNorm.map(([t,n])=>`  ${t}: ${n}`).join('\n')}\n\n`;
  if(anosMatList.length) ctx += `Matérias por ano (recentes):\n${anosMatList.map(([a,n])=>`  ${a}: ${n}`).join('\n')}\n\n`;
  if(anosNormList.length) ctx += `Normas por ano (recentes):\n${anosNormList.map(([a,n])=>`  ${a}: ${n}`).join('\n')}\n\n`;
  // Amostra das últimas 30 matérias
  const amostraMat = allMat.slice(0,30).map(m=>stripAutoria(m.__str__||'')).filter(Boolean);
  if(amostraMat.length) ctx += `Exemplos de matérias:\n${amostraMat.map(m=>`  - ${m}`).join('\n')}\n\n`;
  const amostraNorm = allNorm.slice(0,20).map(n=>stripAutoria(n.__str__||'')).filter(Boolean);
  if(amostraNorm.length) ctx += `Exemplos de normas:\n${amostraNorm.map(n=>`  - ${n}`).join('\n')}\n`;

  agenteContext = ctx;
  agenteBusy = false;

  let h='<div class="agente-wrap">';
  h+=`<div style="font-size:13px;color:var(--muted);padding:12px 16px;background:var(--accent-light);border-radius:10px;border:1px solid #bbf7d0;line-height:1.6">
    <strong style="color:var(--accent-dark)">Agente IA</strong> — Faça perguntas sobre as matérias e normas de <strong>${esc(nomeParlamentar)}</strong>.
    Contexto carregado: <strong style="color:#111827">${allMat.length}</strong> matérias e <strong style="color:#111827">${allNorm.length}</strong> normas.
  </div>`;
  h+='<div class="agente-chat">';
  h+='<div class="agente-msgs" id="agenteMsgs">';
  h+=`<div class="agente-msg"><div class="agente-av ia">IA</div><div class="agente-bubble ia"><p>Olá! Sou o Agente IA de análise legislativa. Posso responder perguntas sobre as matérias e normas de <strong>${esc(nomeParlamentar)}</strong>, como quantidades por tipo, anos mais produtivos, ranking de temas e muito mais.</p></div></div>`;
  h+='</div>';
  h+='<div class="agente-input-bar">';
  h+='<textarea id="agenteInput" class="agente-textarea" placeholder="Pergunte sobre matérias, normas, tipos, anos..." onkeydown="agenteKeydown(event)" oninput="this.style.height=\'40px\';this.style.height=Math.min(this.scrollHeight,120)+\'px\'"></textarea>';
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

function initAgenteEvents() {
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
  row.innerHTML='<div class="agente-av ia">IA</div><div class="agente-bubble ia" style="padding:10px 14px"><div style="display:flex;gap:4px"><div class="agente-dot"></div><div class="agente-dot"></div><div class="agente-dot"></div></div></div>';
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

  const sysContent=`Você é um assistente especializado em análise da produção legislativa de parlamentares brasileiros.
Responda APENAS sobre as matérias e normas do parlamentar descrito abaixo.
Seja objetivo, claro e use linguagem simples. Responda em português brasileiro.
Pode analisar: quantidade por tipo, quantidade por ano, ranking de temas, importância das matérias, padrões na produção legislativa, comparações.

DADOS DO PARLAMENTAR:
${agenteContext||'Dados não disponíveis.'}`;

  const messages=[
    {role:'system',content:sysContent},
    {role:'user',content:q},
  ];

  try{
    const fd=new FormData();
    fd.append('_token',APP_CONFIG.csrf||'');
    fd.append('projeto_id',APP_CONFIG.projetoId||'');
    fd.append('messages',JSON.stringify(messages));
    const res=await fetch(APP_CONFIG.openaiUrl,{method:'POST',body:fd});
    const data=await res.json();
    document.getElementById('agenteTyping')?.remove();
    if(data.error){
      agenteAddMsg('ia','<p style="color:var(--red)">'+esc(data.error)+'</p>');
    }else{
      const text=data.choices?.[0]?.message?.content||'';
      agenteAddMsg('ia',text?agenteFmt(text):'<p>Sem resposta.</p>');
    }
  }catch(e){
    document.getElementById('agenteTyping')?.remove();
    agenteAddMsg('ia','<p style="color:var(--red)">Falha na comunicação com o servidor.</p>');
  }
  agenteBusy=false;
  if(btn) btn.disabled=false;
  document.getElementById('agenteInput')?.focus();
}

// ══════════════════════════════════════════════════════
// Events
// ══════════════════════════════════════════════════════
function onChangeLeg(v){selectedLeg=v;loadMandatos().then(()=>renderList())}
let st;
function onSearch(v){search=v;clearTimeout(st);st=setTimeout(()=>renderGrid(),150)}
function toggleActive(){onlyActive=!onlyActive;renderGrid();document.querySelectorAll('.toggle-btn')[0].classList.toggle('active',onlyActive)}
function toggleTitular(){onlyTitular=!onlyTitular;renderGrid();document.querySelectorAll('.toggle-btn')[1].classList.toggle('active',onlyTitular)}

async function openProfile(id) {
  const p=allParlamentares.find(x=>x.id===id);if(!p)return;
  history.replaceState(null,'','#perfil-'+id);
  currentProfile=p;activeTab='inicio';tablePages={};
  dashboardAllMaterias=null;dashboardAllNormas=null;dashboardChartInstances={};
  agenteContext=null;agenteBusy=false;
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

function backToList(){
  history.replaceState(null,'',location.pathname+location.search);
  currentProfile=null;tabDataCache={};
  dashboardAllMaterias=null;dashboardAllNormas=null;dashboardChartInstances={};
  renderList();window.scrollTo({top:0,behavior:"smooth"});
}

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
      selectedLeg = cached.selectedLeg || (allLegislaturas[0] ? String(allLegislaturas[0].id) : "");
      mandatosByLeg = cached.mandatos || [];
      if(!restoreHashProfile()) renderList();
      return;
    }

    // 2. Cache do servidor (PHP SaplCache via /api/bulk)
    lt.textContent="Carregando parlamentares...";
    const bulk = await fetchBulk();
    if(bulk.fromCache && bulk.parlamentares?.length && bulk.legislaturas?.length) {
      lt.textContent="Carregando parlamentares...";
      allLegislaturas = bulk.legislaturas.sort((a,b)=>(b.numero||0)-(a.numero||0));
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
      if(!restoreHashProfile()) renderList();
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

    if(!restoreHashProfile()) renderList();
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
init();
