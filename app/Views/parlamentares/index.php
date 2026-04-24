<?php /* Projeto sempre vem da sessão — controller garante que está definido */ ?>
<!-- pageTitle / pageSub requeridos pelo app.js -->
<div style="margin-bottom:16px">
  <h2 id="pageTitle" style="font-family:'Playfair Display',serif;font-size:22px;font-weight:800;line-height:1.1">Parlamentares</h2>
  <p id="pageSub" style="font-size:13px;color:var(--muted);margin-top:4px;min-height:18px"></p>
</div>
<div id="mainContent">
  <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:80px 20px;gap:16px">
    <div style="width:40px;height:40px;border:3px solid var(--border);border-top:3px solid var(--accent);border-radius:50%;animation:spin .8s linear infinite"></div>
    <span id="loaderText" style="color:var(--muted);font-size:14px">Carregando...</span>
    <div style="background:var(--border);border-radius:8px;height:6px;width:200px;overflow:hidden">
      <div style="background:var(--accent);height:100%;border-radius:8px;transition:width .3s;width:0%" id="progressFill"></div>
    </div>
  </div>
</div>
<style>
@keyframes spin{to{transform:rotate(360deg)}}
/* Reuse cmjp card/grid styles inline */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px;padding-bottom:40px}
.card{background:#fff;border-radius:14px;border:1px solid var(--border);overflow:hidden;cursor:pointer;transition:transform .25s,box-shadow .25s}
.card:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(26,107,79,0.08)}
.card-img{width:100%;height:220px;object-fit:cover;background:#e5e7eb;display:block}
.card-avatar{width:100%;height:220px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1A6B4F,#0F4030);color:rgba(255,255,255,.85);font-size:64px;font-family:'Playfair Display',serif;font-weight:800}
.card-body{padding:16px 18px 18px}
.card-name{font-size:17px;font-weight:700;line-height:1.25;display:flex;align-items:center;gap:4px}
.card-fullname{font-size:12px;color:var(--muted);margin-top:4px}
.card-party{display:inline-block;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;letter-spacing:.04em;margin-top:8px}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-left:6px}
.dot.on{background:#22C55E;box-shadow:0 0 0 3px rgba(34,197,94,.2)}
.dot.off{background:#DC2626;box-shadow:0 0 0 3px rgba(220,38,38,.15)}
.controls{display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:8px 0 16px}
.controls select{padding:9px 32px 9px 14px;border-radius:10px;border:1.5px solid var(--border);background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 12px center;font-size:14px;font-family:inherit;color:var(--text);cursor:pointer;appearance:none;min-width:200px}
.search-wrap{position:relative;flex:1 1 240px;max-width:400px}
.search-wrap svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none}
.search-wrap input{width:100%;padding:9px 14px 9px 40px;border-radius:10px;border:1.5px solid var(--border);background:#fff;font-size:14px;font-family:inherit;outline:none;transition:border-color .2s}
.search-wrap input:focus{border-color:var(--accent)}
.toggle-group{display:flex;border-radius:10px;overflow:hidden;border:1.5px solid var(--border)}
.toggle-btn{padding:9px 16px;font-size:13px;font-weight:500;font-family:inherit;border:none;cursor:pointer;transition:all .2s;background:#fff;color:var(--muted)}
.toggle-btn.active{background:var(--accent);color:#fff}
.stats{font-size:13px;color:var(--muted);padding:4px 0 16px}
.stats-badge{background:var(--accent-light);color:var(--accent);font-weight:600;padding:3px 10px;border-radius:6px}
.pagination{display:flex;justify-content:center;align-items:center;gap:4px;margin-top:20px;flex-wrap:wrap}
.pg-btn{padding:8px 14px;font-size:13px;font-weight:500;font-family:inherit;border:1.5px solid var(--border);background:#fff;color:var(--text);border-radius:8px;cursor:pointer;transition:all .2s;min-width:38px;text-align:center}
.pg-btn:hover:not(.disabled):not(.active){background:var(--accent-light);border-color:var(--accent);color:var(--accent)}
.pg-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.pg-btn.disabled{opacity:.4;cursor:default;pointer-events:none}
.profile-back{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;border:1.5px solid var(--border);background:#fff;font-size:14px;font-weight:500;font-family:inherit;cursor:pointer;color:var(--text);transition:all .2s;margin-bottom:24px}
.profile-back:hover{border-color:var(--accent);color:var(--accent)}
.profile-hero{display:flex;gap:32px;flex-wrap:wrap;margin-bottom:24px;background:#fff;border-radius:16px;border:1px solid var(--border);padding:24px}
.profile-img{width:160px;height:210px;object-fit:cover;border-radius:12px;border:2px solid var(--border);flex-shrink:0}
.profile-avatar{width:160px;height:210px;border-radius:12px;border:2px solid var(--border);display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1A6B4F,#0F4030);color:#fff;font-size:52px;font-family:'Playfair Display',serif;font-weight:800;flex-shrink:0}
.profile-info{flex:1;min-width:240px}
.profile-info h1{font-family:'Playfair Display',serif;font-size:clamp(22px,3vw,30px);font-weight:800;line-height:1.1;margin-bottom:16px}
.detail-row{display:flex;align-items:center;gap:12px;font-size:14px;margin-bottom:10px}
.detail-label{font-weight:700;min-width:130px;flex-shrink:0}
.tabs-nav{display:flex;gap:0;border-bottom:2px solid var(--border);overflow-x:auto;-webkit-overflow-scrolling:touch;background:#fff;border-radius:12px 12px 0 0;border:1px solid var(--border);border-bottom:2px solid var(--border)}
.tab-btn{padding:14px 20px;font-size:14px;font-weight:500;font-family:inherit;border:none;cursor:pointer;background:transparent;color:var(--muted);border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .2s;white-space:nowrap;display:flex;align-items:center;gap:6px}
.tab-btn:hover{color:var(--text);background:rgba(26,107,79,0.04)}
.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent);font-weight:700;background:var(--accent-light)}
#tabContent{background:#fff;border:1px solid var(--border);border-top:none;border-radius:0 0 12px 12px;padding:24px;min-height:200px}
.kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;margin-bottom:20px}
.kpi-card{background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:14px 12px;text-align:center}
.kpi-icon{font-size:20px;margin-bottom:6px}
.kpi-value{font-family:'Playfair Display',serif;font-size:24px;font-weight:800;color:var(--accent);line-height:1}
.kpi-label{font-size:11px;color:var(--muted);margin-top:6px;font-weight:500;text-transform:uppercase;letter-spacing:.04em}
.chart-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px}
.chart-box{background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:16px}
.frente-tag{display:inline-block;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;background:var(--accent-light);color:var(--accent);border:1px solid rgba(26,107,79,.15);margin:4px}
.section-title{font-family:'Playfair Display',serif;font-size:18px;font-weight:700;margin:0 0 14px;color:var(--text)}
.table-wrap-inner{overflow-x:auto;border-radius:10px;border:1px solid var(--border);background:#fff}
.materia-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.materia-value{font-size:15px;color:var(--text);padding:6px 0;border-bottom:1px solid var(--border)}
.materia-ementa{font-size:14px;line-height:1.7;color:var(--text);margin-top:6px;padding:12px 16px;background:var(--bg);border-radius:8px;border-left:4px solid var(--accent)}
.tag{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;font-size:13px;font-weight:600}
/* materia detail */
.materia-card{background:#fff;border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:20px}
.materia-header{display:flex;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:24px}
.materia-title{font-family:'Playfair Display',serif;font-size:clamp(18px,3vw,24px);font-weight:800;line-height:1.2;flex:1}
.materia-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:0}
.materia-field{padding:10px 0;border-bottom:1px solid var(--border)}
.materia-field:last-child{border-bottom:none}
.materia-value{font-size:15px;color:var(--text);margin-top:3px}
/* table cell helpers */
.td-titular{display:inline-block;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600}
.td-titular.yes{background:var(--accent-light);color:var(--accent)}
.td-titular.no{background:var(--red-light);color:var(--red)}
.td-leg{font-weight:600}
.td-votos{font-weight:700;color:var(--accent);font-family:'Playfair Display',serif}
/* tramitação timeline */
.tram-timeline{display:flex;flex-direction:column}
.tram-item{display:flex;gap:16px;padding:14px 0;border-bottom:1px solid var(--border)}
.tram-item:last-child{border-bottom:none}
.tram-dot{width:10px;height:10px;border-radius:50%;background:var(--border);flex-shrink:0;margin-top:5px}
.tram-latest .tram-dot{background:var(--accent)}
.tram-content{flex:1}
.tram-date{font-size:12px;font-weight:700;color:var(--muted);margin-bottom:2px}
.tram-status{font-size:14px;font-weight:600;color:var(--text);margin-bottom:2px}
.tram-dest{font-size:13px;color:var(--accent);margin-bottom:2px}
.tram-texto{font-size:13px;color:var(--muted)}
/* loaders */
.loader{display:flex;align-items:center;justify-content:center;padding:60px 20px;gap:12px}
.tab-loader{display:flex;align-items:center;justify-content:center;padding:40px 20px;gap:12px}
.spinner{width:24px;height:24px;border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .8s linear infinite;flex-shrink:0}
/* empty states */
.empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:60px 20px;text-align:center}
.empty-tab{padding:32px 20px;text-align:center;color:var(--muted);font-size:14px}
.pg-dots{padding:8px 6px;color:var(--muted);font-size:13px}
.dashboard-grid{display:flex;flex-direction:column;gap:20px}
@media(max-width:900px){.chart-row{grid-template-columns:1fr 1fr}}
@media(max-width:640px){.chart-row{grid-template-columns:1fr}.profile-hero{flex-direction:column;align-items:center}}
</style>
<script>
const APP_CONFIG = {
  proxyBase:   "<?= htmlspecialchars(BASE_PATH) ?>/api/proxy?1=1",
  saplBaseUrl: "<?= htmlspecialchars($saplBaseUrl) ?>",
  source:      "<?= htmlspecialchars($source) ?>",
  basePath:    "<?= htmlspecialchars(BASE_PATH) ?>"
};
</script>
<script src="<?= BASE_PATH ?>/public/app.js"></script>
