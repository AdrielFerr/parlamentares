<?php
/* Cores por região */
$regiaoMeta = [
    'N'  => ['label' => 'Norte',         'cor' => '#0891b2', 'bg' => '#ecfeff', 'border' => '#a5f3fc'],
    'NE' => ['label' => 'Nordeste',      'cor' => '#ea580c', 'bg' => '#fff7ed', 'border' => '#fed7aa'],
    'CO' => ['label' => 'Centro-Oeste',  'cor' => '#7c3aed', 'bg' => '#f5f3ff', 'border' => '#ddd6fe'],
    'SE' => ['label' => 'Sudeste',       'cor' => '#2563eb', 'bg' => '#eff6ff', 'border' => '#bfdbfe'],
    'S'  => ['label' => 'Sul',           'cor' => '#16a34a', 'bg' => '#f0fdf4', 'border' => '#bbf7d0'],
];

/* Cabeçalho da região (ordenado pela linha do mapa, não alfabético) */
$ordemRegioes = ['N','NE','CO','SE','S'];

/* Agrupa estados por região */
$porRegiao = [];
foreach ($estados as $uf => $data) {
    $porRegiao[$data['regiao']][$uf] = $data;
}
?>
<style>
.est-page{max-width:1200px;margin:0 auto;padding:88px 24px 60px}
@media(max-width:600px){.est-page{padding:76px 16px 48px}}

.est-head{margin-bottom:36px}
.est-head h1{font-size:28px;font-weight:800;color:#111827;margin-bottom:6px;letter-spacing:-.4px}
.est-head p{font-size:15px;color:#6b7280}

.regiao-section{margin-bottom:40px}
.regiao-label{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.regiao-dot{width:10px;height:10px;border-radius:50%}
.regiao-title{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7280}

.est-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px}
@media(max-width:480px){.est-grid{grid-template-columns:repeat(2,1fr);gap:10px}}

.est-card{background:#fff;border:1.5px solid #e5e7eb;border-radius:14px;overflow:hidden;cursor:pointer;transition:transform .18s,box-shadow .18s,border-color .18s;text-decoration:none;display:flex;flex-direction:column}
.est-card:hover{transform:translateY(-3px);box-shadow:0 8px 28px rgba(0,0,0,.1);border-color:transparent}

.est-card-top{padding:20px 18px 16px;display:flex;align-items:center;gap:14px}
.est-uf-badge{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:800;color:#fff;flex-shrink:0;letter-spacing:.5px;font-family:'Inter',sans-serif}
.est-card-info{flex:1;min-width:0}
.est-card-name{font-size:14px;font-weight:700;color:#111827;line-height:1.25;margin-bottom:3px}
.est-card-reg{font-size:11px;font-weight:600;padding:2px 7px;border-radius:20px;display:inline-block}

.est-card-metrics{padding:10px 18px 16px;display:flex;gap:12px;border-top:1px solid #f3f4f6;margin-top:auto}
.est-metric{text-align:center;flex:1}
.est-metric-val{font-size:16px;font-weight:800;line-height:1.2;color:#111827}
.est-metric-val.zero{color:#d1d5db}
.est-metric-lbl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin-top:2px}

.est-card-no-data{padding:10px 18px 16px;display:flex;align-items:center;gap:6px;border-top:1px solid #f3f4f6;margin-top:auto}
.est-card-no-data span{font-size:11px;color:#9ca3af}
</style>

<div class="est-page">

  <div class="est-head">
    <h1>Explorar por estado</h1>
    <p>Selecione um estado para ver os parlamentares disponíveis</p>
  </div>

  <?php foreach ($ordemRegioes as $reg):
    if (empty($porRegiao[$reg])) continue;
    $meta = $regiaoMeta[$reg];
  ?>
  <div class="regiao-section">
    <div class="regiao-label">
      <div class="regiao-dot" style="background:<?= $meta['cor'] ?>"></div>
      <span class="regiao-title"><?= $meta['label'] ?></span>
    </div>

    <div class="est-grid">
      <?php foreach ($porRegiao[$reg] as $uf => $data):
        $fed   = $counts['camara_federal'][$uf] ?? 0;
        $sen   = $counts['senado'][$uf]         ?? 0;
        $temExtra = !empty($data['fontes_extras']);
        $extraTotal = 0;
        foreach ($data['fontes_extras'] ?? [] as $fe) {
            $extraTotal += $counts['extras'][$fe['source_key']] ?? 0;
        }
        $temDados = ($fed > 0 || $sen > 0 || $extraTotal > 0);
      ?>
      <a href="<?= BASE_PATH ?>/estados/<?= $uf ?>" class="est-card" style="<?= !$temDados ? 'opacity:.55;cursor:default;pointer-events:none' : '' ?>">
        <div class="est-card-top">
          <div class="est-uf-badge" style="background:<?= $meta['cor'] ?>"><?= $uf ?></div>
          <div class="est-card-info">
            <div class="est-card-name"><?= htmlspecialchars($data['nome']) ?></div>
            <span class="est-card-reg" style="background:<?= $meta['bg'] ?>;color:<?= $meta['cor'] ?>"><?= $meta['label'] ?></span>
          </div>
        </div>

        <?php if ($temDados): ?>
        <div class="est-card-metrics">
          <?php if ($fed > 0): ?>
          <div class="est-metric">
            <div class="est-metric-val"><?= $fed ?></div>
            <div class="est-metric-lbl">Federal</div>
          </div>
          <?php endif; ?>
          <?php if ($sen > 0): ?>
          <div class="est-metric">
            <div class="est-metric-val"><?= $sen ?></div>
            <div class="est-metric-lbl">Senado</div>
          </div>
          <?php endif; ?>
          <?php if ($extraTotal > 0): ?>
          <div class="est-metric">
            <div class="est-metric-val"><?= $extraTotal ?></div>
            <div class="est-metric-lbl">Estadual</div>
          </div>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="est-card-no-data">
          <span>Dados em breve</span>
        </div>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

</div>
