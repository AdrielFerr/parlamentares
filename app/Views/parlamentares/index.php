<?php /* Projeto sempre vem da sessão — controller garante que está definido */ ?>
<!-- pageTitle / pageSub requeridos pelo app.js -->
<h2 id="pageTitle" class="sr-only">Parlamentares</h2>
<p id="pageSub" class="sr-only"></p>
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
body.parlamentares-page .main-content{padding-top:12px}
.sr-only{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes tbounce{0%,80%,100%{transform:translateY(0);opacity:.4}40%{transform:translateY(-7px);opacity:1}}
@keyframes tpulse{0%,100%{transform:scale(1);opacity:.5}50%{transform:scale(1.4);opacity:1}}
/* Reuse cmjp card/grid styles inline */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:10px;padding-bottom:40px}
.card{background:var(--card);backdrop-filter:blur(10px);border-radius:12px;border:1px solid var(--border);overflow:hidden;cursor:pointer;transition:transform .2s,box-shadow .2s}
.card:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(26,107,79,0.1)}
.card-img{width:100%;height:160px;object-fit:cover;object-position:top;background:#e5e7eb;display:block}
.card-avatar{width:100%;height:160px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1A6B4F,#0F4030);color:rgba(255,255,255,.85);font-size:44px;font-family:'Inter',sans-serif;font-weight:800}
.card-body{padding:10px 11px 12px}
.card-name{font-size:13px;font-weight:700;line-height:1.3;display:flex;align-items:center;gap:4px}
.card-fullname{font-size:11px;color:var(--muted);margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.card-meta{display:flex;align-items:center;gap:6px;margin-top:7px;min-height:20px}
.card-party{display:inline-block;padding:3px 9px;border-radius:6px;font-size:11px;font-weight:700;letter-spacing:.04em;line-height:1.2}
.card-uf{display:inline-flex;align-items:center;justify-content:center;min-width:26px;padding:3px 7px;border-radius:6px;background:#f3f4f6;color:#374151;font-size:11px;font-weight:700;line-height:1.2}
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
.profile-back{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:10px;border:1.5px solid var(--border);background:#fff;font-size:14px;font-weight:500;font-family:inherit;cursor:pointer;color:var(--text);transition:all .2s;margin-bottom:12px}
.profile-back:hover{border-color:var(--accent);color:var(--accent)}
.profile-hero{display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px;background:var(--card);backdrop-filter:blur(10px);border-radius:16px;border:1px solid var(--border);padding:20px}
.profile-img{width:140px;height:185px;object-fit:cover;object-position:top;border-radius:12px;border:2px solid var(--border);flex-shrink:0}
.bio-text{font-size:14px;line-height:1.8;color:#111827}
.bio-text p{margin-bottom:10px}.bio-text p:last-child{margin-bottom:0}
.info-row{font-size:14px;color:#111827}
.profile-avatar{width:140px;height:185px;border-radius:12px;border:2px solid var(--border);display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1A6B4F,#0F4030);color:#fff;font-size:46px;font-family:'Inter',sans-serif;font-weight:800;flex-shrink:0}
.profile-info{flex:1;min-width:240px}
.profile-info h1{font-family:'Inter',sans-serif;font-size:clamp(22px,3vw,30px);font-weight:800;line-height:1.1;margin-bottom:16px;letter-spacing:0}
.detail-row{display:flex;align-items:center;gap:12px;font-size:14px;margin-bottom:10px}
.detail-label{font-weight:700;min-width:130px;flex-shrink:0}
.tabs-nav{display:flex;gap:0;border-bottom:2px solid var(--border);overflow-x:auto;-webkit-overflow-scrolling:touch;background:var(--card);backdrop-filter:blur(10px);border-radius:12px 12px 0 0;border:1px solid var(--border);border-bottom:2px solid var(--border);scrollbar-width:none}
.tabs-nav::-webkit-scrollbar{display:none}
.tab-btn{padding:14px 20px;font-size:14px;font-weight:500;font-family:inherit;border:none;cursor:pointer;background:transparent;color:var(--muted);border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .2s;white-space:nowrap;display:flex;align-items:center;gap:6px}
.tab-btn:hover{color:var(--text);background:rgba(26,107,79,0.04)}
.tab-btn.active{color:var(--accent);border-bottom-color:var(--accent);font-weight:700;background:var(--accent-light)}
#tabContent{background:var(--card);backdrop-filter:blur(10px);border:1px solid var(--border);border-top:none;border-radius:0 0 12px 12px;padding:24px;min-height:200px}
.kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;margin-bottom:20px}
.kpi-card{background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:14px 12px;text-align:center}
.kpi-icon{font-size:20px;margin-bottom:6px}
.kpi-value{font-family:'Inter',sans-serif;font-size:24px;font-weight:700;color:var(--accent);line-height:1}
.kpi-label{font-size:11px;color:var(--muted);margin-top:6px;font-weight:500;text-transform:uppercase;letter-spacing:.04em}
.chart-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px}
.chart-box{background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:16px}
.frente-tag{display:inline-block;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;background:var(--accent-light);color:var(--accent);border:1px solid rgba(26,107,79,.15);margin:4px}
.section-title{font-family:'Inter',sans-serif;font-size:17px;font-weight:700;margin:0 0 14px;color:var(--text);letter-spacing:0}
.table-wrap-inner{overflow-x:auto;border-radius:10px;border:1px solid var(--border);background:var(--card)}
.materia-label{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.materia-value{font-size:15px;color:var(--text);padding:6px 0;border-bottom:1px solid var(--border)}
.materia-ementa{font-size:14px;line-height:1.7;color:var(--text);margin-top:6px;padding:12px 16px;background:var(--bg);border-radius:8px;border-left:4px solid var(--accent)}
.tag{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;font-size:13px;font-weight:600}
/* materia detail */
.materia-card{background:var(--card);backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:20px}
.materia-header{display:flex;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:24px}
.materia-title{font-family:'Inter',sans-serif;font-size:clamp(18px,3vw,24px);font-weight:800;line-height:1.2;flex:1;letter-spacing:0}
.materia-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:0}
.materia-field{padding:10px 0;border-bottom:1px solid var(--border)}
.materia-field:last-child{border-bottom:none}
.materia-value{font-size:15px;color:var(--text);margin-top:3px}
/* table cell helpers */
td{color:#111827}
.td-titular{display:inline-block;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600}
.td-titular.yes{background:var(--accent-light);color:var(--accent)}
.td-titular.no{background:var(--red-light);color:var(--red)}
.td-leg{font-weight:600;color:#111827}
.td-votos{font-weight:700;color:var(--accent);font-family:'Inter',sans-serif}
/* tipo accordion (usado em Normas) */
.tipo-list{display:flex;flex-direction:column;gap:6px}
.tipo-block{border:1px solid var(--border);border-radius:10px;overflow:hidden}
.tipo-toggle{width:100%;display:flex;align-items:center;gap:10px;padding:12px 16px;background:#fff;border:none;cursor:pointer;font-family:inherit;font-size:14px;text-align:left;transition:background .15s}
.tipo-toggle:hover{background:var(--accent-light)}
.tipo-toggle-name{flex:1;font-weight:600;color:#111827}
.tipo-toggle-count{background:var(--accent);color:#fff;font-size:11px;font-weight:700;padding:2px 9px;border-radius:12px;flex-shrink:0}
.tipo-caret{color:var(--muted);flex-shrink:0;transition:transform .2s}
.tipo-content{border-top:1px solid var(--border)}
/* tipo-row (tabela de tipos de matérias, clicável) */
.tipo-row:hover td{background:var(--accent-light)}
.tipo-row:hover td:first-child strong{color:var(--accent)}
/* dashboard filters */
.dash-filters{display:flex;align-items:center;flex-wrap:wrap;gap:10px;padding:12px 16px;background:var(--bg);border-radius:10px;border:1px solid var(--border);margin-bottom:16px}
.dash-filter-select{padding:7px 28px 7px 12px;border-radius:8px;border:1.5px solid var(--border);background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") no-repeat right 10px center;font-size:13px;font-family:inherit;color:var(--text);cursor:pointer;appearance:none}
.global-dash-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:10px;border:1.5px solid var(--accent);background:var(--accent);color:#fff;font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;transition:background .2s}
.global-dash-btn:hover{background:var(--accent-dark)}
.global-dashboard{display:flex;flex-direction:column;gap:18px;padding:4px 0 40px}
.global-dash-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap}
.global-dash-title{font-family:'Inter',sans-serif;font-size:24px;font-weight:800;color:var(--text);letter-spacing:0;margin:0;line-height:1.15}
.global-dash-sub{font-size:13px;color:var(--muted);margin-top:5px}
.global-dash-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.global-filter-bar{display:grid;grid-template-columns:repeat(5,minmax(140px,1fr)) auto;gap:10px;align-items:center;padding:12px;background:var(--card);border:1px solid var(--border);border-radius:12px}
.global-filter-bar .dash-filter-select{width:100%;min-width:0;margin:0}
.global-combo{position:relative;min-width:0}
.global-combo-btn{width:100%;height:36px;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 10px 7px 12px;border-radius:8px;border:1.5px solid var(--border);background:#fff;color:var(--text);font-size:13px;font-family:inherit;cursor:pointer;text-align:left}
.global-combo-btn:hover,.global-combo.open .global-combo-btn{border-color:var(--accent)}
.global-combo-label{display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.global-combo-caret{font-size:13px;color:var(--muted);flex-shrink:0}
.global-combo-menu{display:none;position:absolute;z-index:300;top:calc(100% + 6px);left:0;right:0;min-width:260px;background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 12px 28px rgba(0,0,0,.14);overflow:hidden}
.global-combo.open .global-combo-menu{display:block}
.global-combo-search{padding:8px;border-bottom:1px solid var(--border);background:#f9fafb}
.global-combo-search input{width:100%;height:34px;margin:0;padding:8px 10px;border-radius:8px;border:1.5px solid var(--border);font-size:13px;background:#fff}
.global-combo-list{max-height:260px;overflow-y:auto;padding:5px}
.global-combo-item{width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 9px;border:0;border-radius:8px;background:#fff;color:var(--text);font-size:13px;text-align:left;font-family:inherit;cursor:pointer}
.global-combo-item:hover,.global-combo-item.active{background:var(--accent-light);color:var(--accent)}
.global-combo-item-main{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}
.global-combo-item-meta{font-size:11px;color:var(--muted);white-space:nowrap;flex-shrink:0}
.global-combo-empty{padding:14px 10px;color:var(--muted);font-size:12px;text-align:center}
.global-clear-btn{height:36px;padding:0 13px;border-radius:8px;border:1.5px solid var(--border);background:#fff;color:var(--muted);font-size:12px;font-weight:700;font-family:inherit;cursor:pointer;white-space:nowrap}
.global-clear-btn:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-light)}
.global-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px}
.global-kpi{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 15px;min-height:92px;display:flex;flex-direction:column;justify-content:space-between}
.global-kpi.primary{border-color:rgba(22,163,74,.35);background:linear-gradient(180deg,var(--accent-light),rgba(255,255,255,.96))}
.global-kpi-label{font-size:11px;color:var(--muted);font-weight:800;text-transform:uppercase;letter-spacing:.05em;line-height:1.25}
.global-kpi-value{font-size:25px;font-weight:800;color:var(--text);line-height:1.05;margin-top:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.global-kpi.primary .global-kpi-value,.global-kpi.money .global-kpi-value{color:var(--accent)}
.global-kpi-money{font-size:20px}
.global-section{display:flex;flex-direction:column;gap:12px}
.global-section-title{font-size:15px;font-weight:800;color:var(--text);line-height:1.2;margin:0}
.global-chart-grid{display:grid;grid-template-columns:repeat(2,minmax(280px,1fr));gap:14px}
.global-chart-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:15px;min-width:0}
.global-chart-title{font-size:13px;font-weight:800;color:var(--text);margin:0 0 12px;line-height:1.25}
.global-chart-frame{position:relative;height:280px;min-width:0}
.global-chart-scroll{height:280px;overflow-y:auto;overflow-x:hidden;padding-right:6px}
.global-chart-scroll .global-chart-frame{height:calc(var(--chart-items, 10) * 28px);min-height:280px}
.global-chart-scroll .global-html-bars-frame{height:auto!important;min-height:auto!important}
.global-html-bar-list{display:flex;flex-direction:column;gap:7px;padding:2px 2px 8px}
.global-html-bar-row{display:grid;grid-template-columns:minmax(92px,34%) 1fr auto;align-items:center;gap:9px;min-height:22px}
.global-html-bar-label{font-size:11px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.global-html-bar-track{height:10px;background:#eef2f7;border-radius:99px;overflow:hidden;min-width:90px}
.global-html-bar-fill{height:100%;background:#1A6B4F;border-radius:99px}
.global-html-bar-value{font-size:11px;font-weight:800;color:#16a34a;white-space:nowrap;text-align:right}
.global-rank-grid{display:grid;grid-template-columns:repeat(2,minmax(320px,1fr));gap:14px}
.global-rank-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:15px;min-width:0}
.global-table-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}
.global-table-title h3{margin:0;font-size:13px}
.global-rank-scroll{max-height:492px;overflow-y:auto;overflow-x:auto}
.global-rank-scroll .global-rank-table thead th{position:sticky;top:0;z-index:2;background:var(--card);box-shadow:0 1px 0 var(--border)}
.global-rank-table{width:100%;table-layout:fixed}
.global-rank-table th,.global-rank-table td{padding:9px 8px;height:38px;box-sizing:border-box}
.global-rank-table th{height:36px}
.global-rank-table th:nth-child(1),.global-rank-table td:nth-child(1){width:42px;text-align:center}
.global-rank-table th:nth-child(2),.global-rank-table td:nth-child(2){width:230px}
.global-rank-table th:nth-child(2),.global-rank-table td:nth-child(2){padding-right:0}
.global-rank-table th:nth-child(3),.global-rank-table td:nth-child(3){width:108px;padding-left:0}
.global-rank-table th:nth-child(4),.global-rank-table td:nth-child(4){width:auto}
.global-rank-table th:nth-child(5),.global-rank-table td:nth-child(5){width:58px}
.global-rank-name{display:block;font-weight:700;color:var(--text);line-height:1.25;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.global-rank-party{font-size:12px;color:var(--muted);white-space:nowrap}
.global-rank-value{text-align:right;font-weight:800;color:var(--accent);white-space:nowrap}
/* agente IA chat */
.agente-wrap{display:flex;flex-direction:column;gap:14px}
.agente-chat{border:1px solid var(--border);border-radius:12px;overflow:hidden;background:var(--card);backdrop-filter:blur(10px)}
.agente-msgs{padding:16px;display:flex;flex-direction:column;gap:10px;min-height:180px;max-height:380px;overflow-y:auto}
.agente-input-bar{border-top:1px solid var(--border);padding:10px 12px;display:flex;gap:8px}
.agente-textarea{flex:1;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;font-family:inherit;resize:none;outline:none;background:var(--bg);height:40px;max-height:120px;overflow-y:auto}
.agente-textarea:focus{border-color:var(--accent);background:#fff}
.agente-send{width:40px;height:40px;background:var(--accent);color:#fff;border:none;border-radius:9px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .2s}
.agente-send:hover{background:var(--accent-dark)}
.agente-send:disabled{opacity:.45;cursor:default}
.agente-msg{display:flex;gap:8px;align-items:flex-start}
.agente-msg.user{flex-direction:row-reverse}
.agente-av{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;flex-shrink:0;margin-top:2px}
.agente-av.ia{background:var(--accent-light);color:var(--accent);border:1px solid #b6ddc8}
.agente-av.user{background:#f3f4f6;color:var(--muted);border:1px solid var(--border)}
.agente-bubble{padding:10px 14px;border-radius:12px;font-size:13px;line-height:1.6;max-width:80%;word-wrap:break-word}
.agente-bubble.ia{background:var(--bg);border:1px solid var(--border);color:var(--text);border-radius:3px 12px 12px 12px}
.agente-bubble.user{background:var(--accent);color:#fff;border-radius:12px 3px 12px 12px}
.agente-bubble p{margin-bottom:5px}.agente-bubble p:last-child{margin:0}
.agente-bubble strong{font-weight:600}
.agente-bubble ul{margin:4px 0 5px 16px}.agente-bubble li{margin-bottom:2px}
.agente-typing{display:flex;align-items:center;gap:10px;padding:10px 12px}
.agente-typing-dots{display:flex;align-items:flex-end;gap:5px;height:18px}
.agente-dot{width:5px;height:5px;border-radius:50%;background:var(--accent);animation:tbounce 1.1s ease infinite}
.agente-dot:nth-child(2){animation-delay:.18s}.agente-dot:nth-child(3){animation-delay:.36s}
.agente-typing-label{font-size:12px;color:var(--muted);font-weight:500}
.btn-pdf{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:9px;background:var(--accent);color:#fff;font-size:13px;font-weight:600;text-decoration:none;transition:background .2s}
.btn-pdf:hover{background:var(--accent-dark)}
.btn-ver{padding:4px 10px;border-radius:6px;border:1.5px solid var(--border);background:#fff;font-size:11px;font-weight:600;font-family:inherit;cursor:pointer;color:var(--accent);transition:all .15s}
.btn-ver:hover{background:var(--accent-light);border-color:var(--accent)}
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
@media(max-width:768px){
  .grid{grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px}
  .controls{gap:8px;padding-bottom:12px}
  .search-wrap{flex:1 1 100%;max-width:100%}
  .toggle-group{width:100%}
  .toggle-btn{flex:1;font-size:12px;padding:8px 10px}
  #tabContent{padding:14px 12px}
  .tab-btn{font-size:12px;padding:10px 10px}
  .kpi-row{gap:8px}
  .kpi-card{padding:10px 8px}
  .kpi-value{font-size:20px}
  .table-wrap{font-size:13px}
  .materia-header{gap:8px}
  .materia-grid{grid-template-columns:1fr}
  .profile-back{font-size:12px;padding:6px 10px}
  .section-title{font-size:15px}
  .global-dashboard{gap:14px;padding-bottom:28px}
  .global-dash-title{font-size:21px}
  .global-filter-bar{grid-template-columns:1fr 1fr}
  .global-combo-menu{min-width:min(320px,calc(100vw - 44px))}
  .global-clear-btn{grid-column:1/-1;width:100%}
  .global-kpi-grid{grid-template-columns:1fr 1fr;gap:8px}
  .global-kpi{padding:12px;min-height:84px}
  .global-kpi-value{font-size:21px}
  .global-kpi-money{font-size:17px}
  .global-chart-grid,.global-rank-grid{grid-template-columns:1fr;gap:10px}
  .global-chart-card,.global-rank-card{padding:12px}
  .global-chart-frame{height:260px}
  .global-chart-scroll{height:280px}
  .global-chart-scroll .global-chart-frame{min-height:260px}
  .global-rank-table th:nth-child(3),.global-rank-table td:nth-child(3){display:none}
  .global-rank-table th:nth-child(5),.global-rank-table td:nth-child(5){width:52px}
}
@media(max-width:430px){
  .global-filter-bar,.global-kpi-grid{grid-template-columns:1fr}
  .global-rank-table th:nth-child(4),.global-rank-table td:nth-child(4){width:82px}
}
</style>
<script>
document.body.classList.add('parlamentares-page');
const APP_CONFIG = {
  proxyBase:   "<?= htmlspecialchars(BASE_PATH) ?>/api/proxy?1=1",
  saplBaseUrl: "<?= htmlspecialchars($saplBaseUrl) ?>",
  source:      "<?= htmlspecialchars($source) ?>",
  basePath:    "<?= htmlspecialchars(BASE_PATH) ?>",
  projetoId:   <?= (int)($projeto['id'] ?? 0) ?>,
  openaiUrl:   "<?= htmlspecialchars(BASE_PATH) ?>/api/openai",
  csrf:        "<?= htmlspecialchars(Auth::csrfToken()) ?>",
  cacheVer:    "<?= base_convert((string)max(filemtime(ROOT.'/public/app.js'), filemtime(APP.'/Controllers/ApiController.php'), filemtime(ROOT.'/index.php')), 10, 36) ?>",
  nivel:       <?= (int)Auth::nivel() ?>
};
</script>
<?php $appJsVer = max(filemtime(ROOT.'/public/app.js'), filemtime(APP.'/Controllers/ApiController.php'), filemtime(ROOT.'/index.php')); ?>
<script src="<?= htmlspecialchars(asset_url('/public/app.js')) ?>?v=<?= $appJsVer ?>"></script>
