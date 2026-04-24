/**
 * Parlamentares · app.js v3
 * Dashboard tab + multi-fonte + sem referências SAPL
 */

const PROXY_BASE = APP_CONFIG.proxyBase;
const API_BASE   = APP_CONFIG.saplBaseUrl;

let allParlamentares=[], allLegislaturas=[], allPartidos={}, allPartidosSigla={};
let mandatosByLeg=[], selectedLeg="", search="";
let onlyActive=true, onlyTitular=false;
let currentProfile=null, activeTab="dashboard";

let tabDataCache = {};

// ── LocalStorage cache (TTL 1h) ──
const STORAGE_TTL = 3_600_000;
const STORAGE_KEY = `kc_${APP_CONFIG.source}`;

function storageSave(data) {
  try { localStorage.setItem(STORAGE_KEY, JSON.stringify({ts: Date.now(), data})); } catch(e) {}
}
function storageLoad() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if(!raw) return null;
    const {ts, data} = JSON.parse(raw);
    if(Date.now() - ts > STORAGE_TTL) { localStorage.removeItem(STORAGE_KEY); return null; }
    return data;
  } catch(e) { return null; }
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

async function fetchAllPages(basePath, progressCb, maxPages=400, firstPageData=null) {
  let firstData = firstPageData;
  if(!firstData){
    try { firstData = await fetchWithRetry(proxyUrl(basePath,{page:1})); }
    catch(e){ console.warn('[App] Erro inicial:', e.message); return []; }
  }

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
  }
  return items;
}

function getCached(parlId, tabId, fetchFn) {
  if(!tabDataCache[parlId]) tabDataCache[parlId]={};
  if(!tabDataCache[parlId][tabId]) tabDataCache[parlId][tabId] = fetchFn();
  return tabDataCache[parlId][tabId];
}

function getAutorData(p) {
  return getCached(p.id, 'autor', async () => {
    const nome = p.nome_parlamentar || p.nome_completo || "";
    try {
      const d = await fetchWithRetry(proxyUrl('/base/autor/', {nome}));
      if(d?.results?.length > 0) return d.results[0];
    } catch(e) {}
    return null;
  });
}

function stripAutoria(s) { return (s||'').replace(/^Autoria:\s*/i,''); }

function buildCardGrid(list) {
  if(!list.length) return '<div class="empty"><div style="font-size:48px;margin-bottom:12px">🔍</div><p style="font-size:16px;font-weight:500">Nenhum parlamentar encontrado</p><p style="font-size:13px;margin-top:8px">Tente desativar "Apenas Ativos" ou mudar a legislatura.</p></div>';
  let h='<div class="grid">';
  list.forEach(p=>{
    const n=esc(p.nome_parlamentar||p.nome_completo||"?"),s=imgSrc(p),at=!!p.ativo,ini=initials(p.nome_parlamentar||p.nome_completo);
    h+=`<div class="card" onclick="openProfile(${p.id})">`;
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
function imgSrc(p){if(!p.fotografia)return null;return p.fotografia.startsWith("http")?p.fotografia:API_BASE+p.fotografia}
function esc(s){const d=document.createElement("div");d.textContent=s||"";return d.innerHTML}
function fmtDate(d){if(!d)return"—";const p=d.split("-");return p.length===3?p[2]+"/"+p[1]+"/"+p[0]:d}
function stripHtml(s){if(!s)return"";return s.replace(/<[^>]*>/g," ").replace(/&[a-z]+;/gi,c=>{const m={"&amp;":"&","&lt;":"<","&gt;":">","&quot;":'"',"&apos;":"'","&atilde;":"ã","&otilde;":"õ","&eacute;":"é","&iacute;":"í","&oacute;":"ó","&uacute;":"ú","&acirc;":"â","&ecirc;":"ê","&ccedil;":"ç","&Aacute;":"Á","&Eacute;":"É","&Iacute;":"Í","&Oacute;":"Ó","&Uacute;":"Ú","&Atilde;":"Ã","&Otilde;":"Õ","&Ccedil;":"Ç","&nbsp;":" "};return m[c.toLowerCase()]||c}).replace(/\s+/g," ").trim()}

async function getCurrentParty(parlId) {
  return getCached(parlId, '_partido', async () => {
    try {
      const d = await fetchWithRetry(proxyUrl(`/parlamentares/filiacao/?parlamentar=${parlId}&o=-data`, {page:1}));
      const active = (d?.results||[]).find(f=>!f.data_desfiliacao);
      return active ? (allPartidosSigla[active.partido]||null) : null;
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
  h+=`<div class="toggle-group"><button class="toggle-btn${onlyActive?' active':''}" onclick="toggleActive()">Apenas Ativos</button><button class="toggle-btn${onlyTitular?' active':''}" onclick="toggleTitular()">Apenas Titulares</button></div></div>`;
  const li=cl?` na ${cl.numero}ª Legislatura (${new Date(cl.data_inicio).getFullYear()}–${new Date(cl.data_fim).getFullYear()})`:'';
  h+=`<div class="stats" id="listStats"><span class="stats-badge">${list.length}</span> parlamentar${list.length!==1?'es':''} encontrado${list.length!==1?'s':''}${li}</div>`;
  h+='<div id="listGrid"></div>';
  main.innerHTML=h;

  document.getElementById("listGrid").innerHTML=buildCardGrid(list);
}

// ══════════════════════════════════════════════════════
// PROFILE WITH TABS
// ══════════════════════════════════════════════════════
const TABS=[
  {id:'inicio',    label:'Início',              icon:'🏠'},
  {id:'mandatos',  label:'Mandatos',            icon:'📅'},
  {id:'materias',  label:'Matérias',            icon:'📄'},
  {id:'normas',    label:'Normas',              icon:'📜'},
  {id:'filiacoes', label:'Filiações Partidárias',icon:'🏛️'},
  {id:'comissoes', label:'Comissões',           icon:'👥'},
  {id:'relatorias',label:'Relatorias',          icon:'📋'},
  {id:'frentes',   label:'Frentes',             icon:'🤝'},
  {id:'dashboard', label:'Dashboard',           icon:'📊'},
];

async function renderProfileShell(p) {
  const n=esc(p.nome_parlamentar||p.nome_completo||"?"),nc=esc(p.nome_completo||"");
  const s=imgSrc(p),at=!!p.ativo,ini=initials(p.nome_parlamentar||p.nome_completo);
  const partido = await getCurrentParty(p.id);
  const pc = partyColor(partido||"");

  let h='<div style="padding-top:28px;padding-bottom:60px">';
  h+='<button class="profile-back" onclick="backToList()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg> Voltar aos Parlamentares</button>';
  h+='<div class="profile-hero">';
  h+=s?`<img class="profile-img" src="${esc(s)}" alt="${n}" onerror="this.outerHTML='<div class=profile-avatar>${ini}</div>'">`:`<div class="profile-avatar">${ini}</div>`;
  h+='<div class="profile-info">';
  h+=`<h1>${n}</h1>`;
  h+='<div class="profile-details">';
  if(nc&&nc!==n) h+=`<div class="detail-row"><span class="detail-label">Nome Completo:</span><span class="detail-value">${nc}</span></div>`;
  if(partido) h+=`<div class="detail-row"><span class="detail-label">Partido:</span><span class="detail-value"><span class="card-party" style="background:${pc[0]};color:${pc[1]}">${esc(partido)}</span></span></div>`;
  if(p.telefone) h+=`<div class="detail-row"><span class="detail-label">Telefone:</span><span class="detail-value">${esc(p.telefone)}</span></div>`;
  if(p.telefone_celular) h+=`<div class="detail-row"><span class="detail-label">Celular:</span><span class="detail-value">${esc(p.telefone_celular)}</span></div>`;
  if(p.email) h+=`<div class="detail-row"><span class="detail-label">E-mail:</span><span class="detail-value"><a href="mailto:${esc(p.email)}" style="color:var(--accent)">${esc(p.email)}</a></span></div>`;
  if(p.endereco_web) h+=`<div class="detail-row"><span class="detail-label">Homepage:</span><span class="detail-value"><a href="${esc(p.endereco_web)}" target="_blank" style="color:var(--accent)">${esc(p.endereco_web)}</a></span></div>`;
  if(p.numero_gab_parlamentar) h+=`<div class="detail-row"><span class="detail-label">Nº Gabinete:</span><span class="detail-value">${esc(p.numero_gab_parlamentar)}</span></div>`;
  if(p.profissao) h+=`<div class="detail-row"><span class="detail-label">Profissão:</span><span class="detail-value">${esc(p.profissao)}</span></div>`;
  h+=`<div class="detail-row"><span class="detail-label">Situação:</span><span class="detail-value"><span class="tag" style="background:${at?'var(--accent-light)':'var(--red-light)'};color:${at?'var(--accent)':'var(--red)'}"><span class="dot ${at?'on':'off'}"></span> ${at?'Ativo':'Inativo'}</span></span></div>`;
  h+='</div></div></div>';
  h+='<div class="tabs-nav">';
  TABS.forEach(t=>{h+=`<button class="tab-btn${activeTab===t.id?' active':''}" onclick="switchTab('${t.id}')">${t.icon} <span class="tab-label">${t.label}</span></button>`});
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
    el.innerHTML=html;
    if(tabId==='dashboard') setTimeout(initDashboardCharts,0);
  }catch(e){el.innerHTML=`<div class="empty-tab" style="color:var(--red)">Erro: ${esc(e.message)}</div>`}
}

// ══════════════════════════════════════════════════════
// TAB: DASHBOARD
// ══════════════════════════════════════════════════════
let dashboardChartData = null;

async function renderTabDashboard(p) {
  const autorData = await getAutorData(p);
  if(!autorData) return '<div class="empty-tab">Autor não encontrado para este parlamentar.</div>';

  const [materiasP1, normasP1] = await Promise.all([
    fetchWithRetry(proxyUrl(`/materia/autoria/?autor=${autorData.id}&o=-id`, {page:1})).catch(()=>null),
    fetchWithRetry(proxyUrl(`/norma/autorianorma/?autor=${autorData.id}`, {page:1})).catch(()=>null),
  ]);

  const totalMaterias = materiasP1?.pagination?.total_entries ?? (materiasP1?.results?.length ?? 0);
  const totalNormas   = normasP1?.pagination?.total_entries   ?? (normasP1?.results?.length   ?? 0);

  // Autoria: calculado da página 1 (amostra)
  const p1 = materiasP1?.results || [];
  const p1Primeiro = p1.filter(a=>a.primeiro_autor).length;
  const totalPrimeiro  = p1.length > 0 ? Math.round((p1Primeiro / p1.length) * totalMaterias) : 0;
  const totalCoautoria = totalMaterias - totalPrimeiro;

  // Anos: busca qualquer sequência de 4 dígitos que pareça ano (1990–2030)
  const materiasByYear = {};
  p1.forEach(m=>{
    const years = [...(m.__str__||'').matchAll(/\b((?:19|20)\d{2})\b/g)].map(x=>x[1]);
    if(years.length) { const yr=years[years.length-1]; materiasByYear[yr]=(materiasByYear[yr]||0)+1; }
  });

  // Tipos de norma
  const normasByType = {};
  (normasP1?.results||[]).forEach(n=>{
    const str = stripAutoria(n.__str__||'');
    const match = str.match(/^([A-Za-záàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇ\.]+(?:\s+[A-Za-záàãâéêíóôõúüçÁÀÃÂÉÊÍÓÔÕÚÜÇ\.]+)*)\s+(?:n[ºo°.]|nº|\d)/i);
    const tipo = match ? match[1].trim() : 'Outros';
    normasByType[tipo] = (normasByType[tipo]||0)+1;
  });

  dashboardChartData = { materiasByYear, normasByType, autorBreakdown:{primeiro:totalPrimeiro, coautoria:totalCoautoria} };

  const kpis=[
    {icon:'📄', value:totalMaterias.toLocaleString('pt-BR'),  label:'Matérias'},
    {icon:'📜', value:totalNormas.toLocaleString('pt-BR'),    label:'Normas'},
    {icon:'🤝', value:totalCoautoria.toLocaleString('pt-BR'), label:'Co-participação'},
  ];

  let h='<div class="dashboard-grid">';
  h+='<div class="kpi-row">';
  kpis.forEach(k=>{ h+=`<div class="kpi-card"><div class="kpi-icon">${k.icon}</div><div class="kpi-value">${k.value}</div><div class="kpi-label">${k.label}</div></div>`; });
  h+='</div><div class="chart-row">';
  h+=`<div class="chart-box"><h3 class="section-title" style="margin-bottom:12px">📊 Matérias por Ano</h3><div style="position:relative;height:200px"><canvas id="chartProdAnual"></canvas></div></div>`;
  h+=`<div class="chart-box"><h3 class="section-title" style="margin-bottom:12px">📜 Normas por Tipo</h3><div style="position:relative;height:200px"><canvas id="chartNormasTipo"></canvas></div></div>`;
  h+=`<div class="chart-box"><h3 class="section-title" style="margin-bottom:12px">✍️ Autoria</h3><div style="position:relative;height:200px"><canvas id="chartAutoria"></canvas></div></div>`;
  h+='</div></div>';
  return h;
}

function initDashboardCharts() {
  if(!dashboardChartData || typeof Chart==='undefined') return;
  const {materiasByYear, normasByType, autorBreakdown} = dashboardChartData;
  const COLORS=['#1A6B4F','#C9A84C','#3B82F6','#EC4899','#8B5CF6','#F59E0B','#10B981','#EF4444','#0EA5E9','#A855F7'];
  const font = {family:"'Inter',sans-serif"};

  function mountChart(id, config) {
    const canvas = document.getElementById(id);
    if(!canvas) return;
    new Chart(canvas, config);
  }

  const years = Object.keys(materiasByYear).sort();
  if(years.length) {
    mountChart('chartProdAnual',{
      type:'bar',
      data:{labels:years,datasets:[{label:'Matérias',data:years.map(y=>materiasByYear[y]),backgroundColor:'#1A6B4F',borderRadius:6,borderSkipped:false}]},
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{title:l=>'Ano '+l[0].label}}},scales:{y:{beginAtZero:true,ticks:{stepSize:1,font},grid:{color:'rgba(0,0,0,.05)'}},x:{ticks:{font},grid:{display:false}}}}
    });
  } else {
    const el=document.getElementById('chartProdAnual');
    if(el) el.closest('.chart-box').innerHTML+='<p style="text-align:center;color:var(--muted);font-size:13px;margin-top:8px">Sem dados na página atual</p>';
  }

  const tipos = Object.keys(normasByType);
  if(tipos.length) {
    mountChart('chartNormasTipo',{
      type:'bar',
      data:{labels:tipos,datasets:[{label:'Normas',data:tipos.map(t=>normasByType[t]),backgroundColor:COLORS.slice(0,tipos.length),borderRadius:6,borderSkipped:false}]},
      options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1,font},grid:{color:'rgba(0,0,0,.05)'}},x:{ticks:{font,maxRotation:30},grid:{display:false}}}}
    });
  }

  if(autorBreakdown && (autorBreakdown.primeiro||autorBreakdown.coautoria)) {
    mountChart('chartAutoria',{
      type:'doughnut',
      data:{
        labels:['1º Autor','Co-participação'],
        datasets:[{data:[autorBreakdown.primeiro,autorBreakdown.coautoria],backgroundColor:['#1A6B4F','#C9A84C'],borderWidth:3,borderColor:'#fff',hoverOffset:6}]
      },
      options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:'bottom',labels:{font,padding:16,usePointStyle:true,pointStyle:'circle'}}}}
    });
  }
}

// ── Tab: Início ──
async function renderTabInicio(p) {
  let h='';
  const bio=stripHtml(p.biografia||"");
  if(bio){
    h+=`<h3 class="section-title">Biografia</h3>`;
    h+=`<div class="bio-text">${esc(bio)}</div>`;
  }
  if(p.locais_atuacao) h+=`<div class="info-row"><strong>Locais de Atuação:</strong> ${esc(p.locais_atuacao)}</div>`;
  if(!bio&&!p.locais_atuacao) h+='<div class="empty-tab">Nenhuma informação adicional disponível.</div>';
  return h;
}

// ── Tab: Mandatos ──
async function renderTabMandatos(p) {
  const mandatos=await getCached(p.id,'mandatos',()=>fetchAllPages(`/parlamentares/mandato/?parlamentar=${p.id}`));
  mandatos.sort((a,b)=>(b.legislatura||0)-(a.legislatura||0));
  const lm={};allLegislaturas.forEach(l=>lm[l.id]=l);
  if(!mandatos.length) return '<div class="empty-tab">Nenhum mandato encontrado.</div>';

  let h=`<h3 class="section-title">Total de Mandatos: ${mandatos.length}</h3>`;
  const thead='<tr><th>Legislatura</th><th>Votos Recebidos</th><th>Coligação</th><th>Titular</th></tr>';
  h+=paginateTable(mandatos,10,tablePages['pg-mandatos']||1,m=>{
    const l=lm[m.legislatura];
    const legLabel=l?`${l.numero}ª (${new Date(l.data_inicio).getFullYear()} - ${new Date(l.data_fim).getFullYear()})${l.id===allLegislaturas[0]?.id?' (Atual)':''}`:'#'+m.legislatura;
    return `<tr>
      <td><span class="td-leg">${legLabel}</span></td>
      <td><span class="td-votos">${m.votos_recebidos?Number(m.votos_recebidos).toLocaleString('pt-BR'):'—'}</span></td>
      <td>${m.coligacao?'Coligação #'+m.coligacao:'—'}</td>
      <td><span class="td-titular ${m.titular?'yes':'no'}">${m.titular?'Sim':'Não'}</span></td>
    </tr>`;
  },thead,'pg-mandatos');
  return h;
}

// ── Tab: Matérias ──
async function renderTabMaterias(p) {
  const autorData = await getAutorData(p);
  if(!autorData) return '<div class="empty-tab">Autor não encontrado para este parlamentar.</div>';
  tablePages['pg-materias'] = tablePages['pg-materias'] || 1;
  return renderMateriasPage(p, autorData.id);
}

async function renderMateriasPage(p, autorId) {
  const pg=tablePages['pg-materias']||1;
  const data=await fetchWithRetry(proxyUrl(`/materia/autoria/?autor=${autorId}&o=-id`,{page:pg}));
  if(!data?.results) return '<div class="empty-tab">Nenhuma matéria encontrada.</div>';

  const items=data.results;
  const total=data.pagination?.total_entries||0;
  const totalPages=data.pagination?.total_pages||1;

  let h=`<h3 class="section-title">Matérias Legislativas</h3>`;
  h+=`<div style="margin-bottom:16px;font-size:14px;color:var(--muted)">Total de autorias: <strong style="color:var(--accent)">${total.toLocaleString('pt-BR')}</strong> · Página ${pg} de ${totalPages}</div>`;
  h+='<div class="table-wrap"><table><thead><tr><th>Matéria</th><th>1º Autor</th></tr></thead><tbody>';
  items.forEach(a=>{
    const label=stripAutoria(a.__str__).replace(/^.*?\s-\s/,"");
    h+=`<tr>`;
    h+=`<td><a href="javascript:void(0)" onclick="openMateria(${a.materia})" style="color:var(--accent);font-weight:500">${esc(label)}</a></td>`;
    h+=`<td><span class="td-titular ${a.primeiro_autor?'yes':'no'}">${a.primeiro_autor?'Sim':'Não'}</span></td>`;
    h+=`</tr>`;
  });
  h+='</tbody></table></div>';

  if(totalPages>1){
    h+='<div class="pagination">';
    h+=`<button class="pg-btn${pg<=1?' disabled':''}" onclick="goMateriasPage(${pg-1})">← Anterior</button>`;
    for(let i=1;i<=totalPages;i++){
      if(i===1||i===totalPages||(i>=pg-2&&i<=pg+2)){
        h+=`<button class="pg-btn${i===pg?' active':''}" onclick="goMateriasPage(${i})">${i}</button>`;
      }else if(i===pg-3||i===pg+3){
        h+='<span class="pg-dots">...</span>';
      }
    }
    h+=`<button class="pg-btn${pg>=totalPages?' disabled':''}" onclick="goMateriasPage(${pg+1})">Próxima →</button>`;
    h+='</div>';
  }
  return h;
}

async function goMateriasPage(pg) {
  tablePages['pg-materias']=pg;
  const el=document.getElementById('tabContent');
  el.innerHTML='<div class="tab-loader"><div class="spinner"></div></div>';
  try{
    const autorData = await getAutorData(currentProfile);
    el.innerHTML=await renderMateriasPage(currentProfile, autorData?.id);
  }catch(e){el.innerHTML=`<div class="empty-tab" style="color:var(--red)">Erro: ${esc(e.message)}</div>`}
  el.scrollIntoView({behavior:'smooth',block:'start'});
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
      h+=`<div style="margin-top:16px"><span class="materia-label">Texto Original</span><div style="margin-top:6px"><a href="${esc(textoUrl)}" target="_blank" style="color:var(--accent);font-weight:500">📄 ${esc(textoUrl.split('/').pop())}</a></div></div>`;
    }
    h+='</div>';

    if(autores.length>0){
      h+='<div class="materia-card">';
      h+=`<h3 class="section-title">Autores (${autores.length})</h3>`;
      h+='<div class="table-wrap"><table><thead><tr><th>Autor</th><th>1º Autor</th></tr></thead><tbody>';
      autores.forEach(a=>{
        const nome=(a.__str__||'').replace(/^Autoria:.*?-\s*/,'').trim()||a.__str__||'—';
        h+=`<tr><td style="font-weight:500">${esc(nome)}</td><td><span class="td-titular ${a.primeiro_autor?'yes':'no'}">${a.primeiro_autor?'Sim':'Não'}</span></td></tr>`;
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
        h+=fileUrl?`<td><a href="${esc(fileUrl)}" target="_blank" style="color:var(--accent);font-weight:500">📄 ${esc(nome)}</a></td>`:`<td>${esc(nome)}</td>`;
        h+=`<td>${esc(tipo)}</td><td>${fmtDate(d.data)}</td></tr>`;
      });
      h+='</tbody></table></div></div>';
    }

    {
      const gridFields = [
        ['Apelido',              m.apelido],
        ['Objeto',               m.objeto],
        ['Resultado',            m.resultado],
        ['Data de Publicação',   m.data_publicacao ? fmtDate(m.data_publicacao) : null],
        ['Data de Vigência',     m.data_vigencia   ? fmtDate(m.data_vigencia)   : null],
        ['Nº Origem Externa',    m.numero_origem_externa],
        ['Data Origem Externa',  m.data_origem_externa  ? fmtDate(m.data_origem_externa) : null],
        ['Local Origem Externa', m.local_origem_externa],
        ['Apreciação',           m.apreciacao],
        ['Complementar?',        m.complementar != null ? (m.complementar ? 'Sim' : 'Não') : null],
        ['Matéria Polêmica?',    m.polemica      != null ? (m.polemica      ? 'Sim' : 'Não') : null],
      ];
      const textFields = [
        ['Ementa Diário',        m.ementa_diario],
        ['Legislação Citada',    m.legislacao_citada],
        ['Indexação',            m.indexacao],
        ['Tipificação Textual',  m.tipificacao_textual],
        ['Observação',           m.observacao],
      ];
      const hasAny = gridFields.some(([,v])=>v!=null&&v!=='') || textFields.some(([,v])=>v);
      if(hasAny){
        h+='<div class="materia-card"><h3 class="section-title">Outras Informações</h3>';
        h+='<div class="materia-grid">';
        gridFields.forEach(([label,val])=>{
          if(val!=null&&val!=='') h+=`<div class="materia-field"><span class="materia-label">${label}</span><div class="materia-value">${esc(String(val))}</div></div>`;
        });
        h+='</div>';
        textFields.forEach(([label,val])=>{
          if(val) h+=`<div style="margin-top:16px"><span class="materia-label">${label}</span><div style="margin-top:4px;color:var(--muted);font-size:13px;line-height:1.6">${esc(val)}</div></div>`;
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

// ── Tab: Normas ──
async function renderTabNormas(p) {
  const autorData = await getAutorData(p);
  if(!autorData) return '<div class="empty-tab">Autor não encontrado.</div>';

  const normas=await getCached(p.id,'normas',()=>fetchAllPages(`/norma/autorianorma/?autor=${autorData.id}`));
  if(!normas.length) return '<div class="empty-tab">Nenhuma norma jurídica encontrada.</div>';
  normas.sort((a,b)=>(b.__str__||"").localeCompare(a.__str__||""));

  let h=`<h3 class="section-title">Normas Jurídicas (${normas.length})</h3>`;
  const thead='<tr><th>Norma</th><th>Primeiro Autor</th></tr>';
  h+=paginateTable(normas,10,tablePages['pg-normas']||1,n=>`<tr>
      <td>${esc(stripAutoria(n.__str__))}</td>
      <td><span class="td-titular ${n.primeiro_autor?'yes':'no'}">${n.primeiro_autor?'Sim':'Não'}</span></td>
    </tr>`,thead,'pg-normas');
  return h;
}

// ── Tab: Filiações ──
async function renderTabFiliacoes(p) {
  const fil=await getCached(p.id,'_filiacoes',()=>fetchAllPages(`/parlamentares/filiacao/?parlamentar=${p.id}`));
  fil.sort((a,b)=>(b.data||"").localeCompare(a.data||""));
  if(!fil.length) return '<div class="empty-tab">Nenhuma filiação encontrada.</div>';

  let h=`<h3 class="section-title">Filiações Partidárias (${fil.length})</h3>`;
  const thead='<tr><th>Partido</th><th>Data Filiação</th><th>Data Desfiliação</th><th>Status</th></tr>';
  h+=paginateTable(fil,10,tablePages['pg-filiacoes']||1,f=>{
    const sigla=allPartidosSigla[f.partido]||"?",nome=allPartidos[f.partido]||"";
    const isActive=!f.data_desfiliacao,pc=partyColor(sigla);
    return `<tr>
      <td><span class="card-party" style="background:${pc[0]};color:${pc[1]};margin-right:8px">${esc(sigla)}</span> ${esc(nome)}</td>
      <td>${fmtDate(f.data)}</td>
      <td>${fmtDate(f.data_desfiliacao)}</td>
      <td><span class="td-titular ${isActive?'yes':'no'}">${isActive?'Atual':'Encerrada'}</span></td>
    </tr>`;
  },thead,'pg-filiacoes');
  return h;
}

// ── Tab: Comissões ──
async function renderTabComissoes(p) {
  const parts=await getCached(p.id,'comissoes',()=>fetchAllPages(`/comissoes/participacao/?parlamentar=${p.id}`));
  parts.sort((a,b)=>(b.data_designacao||"").localeCompare(a.data_designacao||""));
  if(!parts.length) return '<div class="empty-tab">Nenhuma participação em comissão encontrada.</div>';

  let h=`<h3 class="section-title">Comissões (${parts.length})</h3>`;
  const thead='<tr><th>Comissão / Cargo</th><th>Data Designação</th><th>Data Desligamento</th><th>Titular</th></tr>';
  h+=paginateTable(parts,10,tablePages['pg-comissoes']||1,c=>{
    return `<tr>
      <td>${esc(c.__str__)}</td>
      <td>${fmtDate(c.data_designacao)}</td>
      <td>${fmtDate(c.data_desligamento)}</td>
      <td><span class="td-titular ${c.titular?'yes':'no'}">${c.titular?'Sim':'Não'}</span></td>
    </tr>`;
  },thead,'pg-comissoes');
  return h;
}

// ── Tab: Relatorias ──
async function renderTabRelatorias(p) {
  const rels=await getCached(p.id,'relatorias',()=>fetchAllPages(`/materia/relatoria/?parlamentar=${p.id}`));
  rels.sort((a,b)=>(b.data_designacao_relator||"").localeCompare(a.data_designacao_relator||""));
  if(!rels.length) return '<div class="empty-tab">Nenhuma relatoria encontrada.</div>';

  let h=`<h3 class="section-title">Relatorias (${rels.length})</h3>`;
  const thead='<tr><th>Matéria</th><th>Data Designação</th><th>Data Destituição</th></tr>';
  h+=paginateTable(rels,10,tablePages['pg-relatorias']||1,r=>{
    return `<tr>
      <td>${esc(r.__str__)}</td>
      <td>${fmtDate(r.data_designacao_relator)}</td>
      <td>${fmtDate(r.data_destituicao_relator)}</td>
    </tr>`;
  },thead,'pg-relatorias');
  return h;
}

// ── Tab: Frentes ──
async function renderTabFrentes(p) {
  const frentes=await getCached(p.id,'frentes',()=>fetchAllPages(`/parlamentares/frenteparlamentar/?parlamentar=${p.id}`));
  if(!frentes.length) return '<div class="empty-tab">Nenhuma participação em frente parlamentar encontrada.</div>';

  const [cargos, allFrentes]=await Promise.all([
    getCached('global','frentecargos',()=>fetchAllPages('/parlamentares/frentecargo/')),
    getCached('global','allfrentes',  ()=>fetchAllPages('/parlamentares/frente/')),
  ]);
  const cargoMap={};cargos.forEach(c=>cargoMap[c.id]=c.nome_cargo);
  const frenteMap={};allFrentes.forEach(f=>frenteMap[f.id]=f);

  let h=`<h3 class="section-title">Frentes Parlamentares</h3>`;
  h+=`<div style="margin-bottom:16px;font-size:14px;color:var(--muted)">Total: <strong style="color:var(--accent)">${frentes.length}</strong></div>`;
  h+='<div class="table-wrap"><table><thead><tr><th>Frente</th><th>Cargo</th><th>Data de Entrada</th><th>Data de Saída</th></tr></thead><tbody>';
  frentes.forEach(f=>{
    const frente=frenteMap[f.frente];
    const nome=frente?frente.nome:(f.__str__||'Frente #'+f.frente);
    const cargo=cargoMap[f.cargo]||'—';
    h+=`<tr>
      <td><strong style="color:var(--accent)">${esc(nome)}</strong></td>
      <td>${esc(cargo)}</td>
      <td>${fmtDate(f.data_entrada)}</td>
      <td>${fmtDate(f.data_saida)}</td>
    </tr>`;
  });
  h+='</tbody></table></div>';
  return h;
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
  currentProfile=p;activeTab='inicio';tablePages={};dashboardChartData=null;
  const main=document.getElementById("mainContent");
  main.innerHTML='<div class="loader"><div class="spinner"></div><span style="color:var(--muted);font-size:14px">Carregando perfil...</span></div>';
  main.innerHTML=await renderProfileShell(p);
  switchTab('inicio');
  window.scrollTo({top:0,behavior:"smooth"});
}

function backToList(){
  currentProfile=null;tabDataCache={};dashboardChartData=null;
  renderList();window.scrollTo({top:0,behavior:"smooth"});
}

async function loadMandatos(){
  if(!selectedLeg){mandatosByLeg=[];return}
  try{mandatosByLeg=await fetchAllPages("/parlamentares/mandato/?legislatura="+selectedLeg)}catch(e){mandatosByLeg=[]}
}

// ══════════════════════════════════════════════════════
// Init
// ══════════════════════════════════════════════════════
async function init(){
  const lt=document.getElementById("loaderText"),pf=document.getElementById("progressFill");
  try{
    // Tenta carregar do localStorage primeiro
    const cached = storageLoad();
    if(cached){
      lt.textContent="Carregando do cache...";
      allLegislaturas = cached.legislaturas;
      allParlamentares = cached.parlamentares;
      cached.partidos.forEach(p=>{allPartidos[p.id]=p.nome;allPartidosSigla[p.id]=p.sigla});
      selectedLeg = cached.selectedLeg || (allLegislaturas[0] ? String(allLegislaturas[0].id) : "");
      mandatosByLeg = cached.mandatos || [];
      renderList();
      return;
    }

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
        pf.style.width=Math.round(done/total*100)+"%";
      }),
      loadMandatos(),
    ]);
    allParlamentares=parls;

    fetch(APP_CONFIG.basePath+'/api/parl-count',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'total='+parls.length}).catch(()=>{});

    storageSave({
      legislaturas: allLegislaturas,
      parlamentares: allParlamentares,
      partidos: partidosRaw,
      mandatos: mandatosByLeg,
      selectedLeg,
    });

    renderList();
  }catch(e){
    console.error(e);
    document.getElementById("mainContent").innerHTML=`<div class="empty">
      <p style="font-size:18px;font-weight:600;color:var(--red)">Erro ao carregar dados</p>
      <p style="font-size:14px;margin-top:8px;color:var(--muted)">${esc(e.message)}</p>
      <p style="font-size:13px;margin-top:12px;color:var(--muted)">Possíveis causas: API fora do ar, timeout na conexão ou resposta vazia.</p>
      <button onclick="location.reload()" style="margin-top:16px;padding:10px 24px;border-radius:10px;border:1.5px solid var(--accent);background:var(--accent);color:#fff;font-size:14px;font-family:inherit;cursor:pointer">🔄 Tentar novamente</button>
      <p style="font-size:12px;margin-top:12px;color:var(--muted)">Proxy: ${esc(PROXY_BASE)}</p>
    </div>`;
  }
}
init();
