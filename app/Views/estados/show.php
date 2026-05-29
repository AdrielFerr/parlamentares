<?php
$regiaoMeta = [
    'N'  => ['label' => 'Norte',        'cor' => '#0891b2', 'bg' => '#ecfeff'],
    'NE' => ['label' => 'Nordeste',     'cor' => '#ea580c', 'bg' => '#fff7ed'],
    'CO' => ['label' => 'Centro-Oeste', 'cor' => '#7c3aed', 'bg' => '#f5f3ff'],
    'SE' => ['label' => 'Sudeste',      'cor' => '#2563eb', 'bg' => '#eff6ff'],
    'S'  => ['label' => 'Sul',          'cor' => '#16a34a', 'bg' => '#f0fdf4'],
];
$reg  = $regiaoMeta[$estado['regiao']] ?? $regiaoMeta['SE'];
$csrf = Auth::csrfToken();

/* Monta cargos nacionais + extras */
$nacionais = [
    ['source_key' => 'camara_federal', 'label' => 'Câmara Federal',       'icon' => 'ph-buildings',    'apply_uf' => true,  'count' => $nationalCounts['camara_federal'] ?? 0],
    ['source_key' => 'senado',         'label' => 'Senado Federal',        'icon' => 'ph-building',     'apply_uf' => true,  'count' => $nationalCounts['senado'] ?? 0],
];

$extrasPorCargo = [];
foreach ($estado['fontes_extras'] ?? [] as $fe) {
    $fe['count']     = $extraCounts[$fe['source_key']] ?? 0;
    $extrasPorCargo[$fe['cargo']][] = $fe;
}

/* Cargos desativados (sem dados ainda) */
$desativados = [
    ['label' => 'Governador',   'icon' => 'ph-flag'],
    ['label' => 'Prefeito',     'icon' => 'ph-city'],
];
?>
<style>
.show-page{max-width:900px;margin:0 auto;padding:88px 24px 60px}
@media(max-width:600px){.show-page{padding:76px 16px 48px}}

.show-back{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border:1.5px solid #e5e7eb;border-radius:9px;font-size:13px;font-weight:600;color:#374151;background:#fff;text-decoration:none;transition:all .15s;margin-bottom:28px}
.show-back:hover{border-color:#16a34a;color:#16a34a}

.show-hero{display:flex;align-items:center;gap:20px;margin-bottom:36px}
.show-uf{width:72px;height:72px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:800;color:#fff;flex-shrink:0;letter-spacing:.5px;font-family:'Inter',sans-serif}
.show-hero-info h1{font-size:26px;font-weight:800;color:#111827;margin-bottom:4px}
.show-hero-info .reg-badge{font-size:12px;font-weight:600;padding:3px 10px;border-radius:20px;display:inline-block}

.section-title{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6b7280;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.section-title::after{content:'';flex:1;height:1px;background:#f3f4f6}

.cargo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:32px}
@media(max-width:480px){.cargo-grid{grid-template-columns:1fr}}

.cargo-card{background:#fff;border:1.5px solid #e5e7eb;border-radius:14px;padding:20px;display:flex;flex-direction:column;gap:12px;transition:transform .18s,box-shadow .18s,border-color .18s;cursor:pointer;position:relative;overflow:hidden;text-align:left}
.cargo-card:hover:not(:disabled):not(.disabled){transform:translateY(-2px);box-shadow:0 6px 24px rgba(0,0,0,.09);border-color:transparent}
.cargo-card.disabled{opacity:.45;cursor:not-allowed;background:#fafafa}

.cargo-icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:22px}
.cargo-name{font-size:15px;font-weight:700;color:#111827;line-height:1.25}
.cargo-count{font-size:12px;color:#6b7280;font-weight:500}
.cargo-count strong{color:#111827}
.cargo-coming{font-size:11px;color:#9ca3af;font-weight:500}
.cargo-arrow{position:absolute;bottom:18px;right:16px;color:#d1d5db;font-size:18px;transition:color .15s}
.cargo-card:hover:not(.disabled) .cargo-arrow{color:#16a34a}

.municipal-list{display:flex;flex-direction:column;gap:8px;margin-bottom:32px}
.mun-item{background:#fff;border:1.5px solid #e5e7eb;border-radius:11px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer;transition:all .15s}
.mun-item:hover{border-color:#16a34a;background:#f0fdf4}
.mun-item-info{display:flex;align-items:center;gap:12px}
.mun-icon{width:36px;height:36px;border-radius:8px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;font-size:17px;color:#16a34a;flex-shrink:0}
.mun-name{font-size:14px;font-weight:600;color:#111827}
.mun-count{font-size:12px;color:#6b7280}
.mun-arrow{color:#9ca3af;font-size:16px}
</style>

<div class="show-page">
  <a href="<?= BASE_PATH ?>/estados" class="show-back">
    <i class="ph ph-arrow-left"></i> Todos os estados
  </a>

  <div class="show-hero">
    <div class="show-uf" style="background:<?= $reg['cor'] ?>"><?= htmlspecialchars($uf) ?></div>
    <div class="show-hero-info">
      <h1><?= htmlspecialchars($estado['nome']) ?></h1>
      <span class="reg-badge" style="background:<?= $reg['bg'] ?>;color:<?= $reg['cor'] ?>"><?= $reg['label'] ?></span>
    </div>
  </div>

  <!-- Cargos nacionais -->
  <div class="section-title">Âmbito federal</div>
  <div class="cargo-grid">
    <?php foreach ($nacionais as $cargo):
      $temProjeto = isset($projetosBySource[$cargo['source_key']]);
      $temDados   = $cargo['count'] > 0;
      $ativo      = $temProjeto && $temDados;
    ?>
    <div class="cargo-card <?= $ativo ? '' : 'disabled' ?>"
         <?php if ($ativo): ?>onclick="selecionarCargo('<?= htmlspecialchars($cargo['source_key']) ?>','<?= $ativo && $cargo['apply_uf'] ? '1' : '0' ?>')"<?php endif; ?>>
      <div class="cargo-icon" style="background:<?= $reg['bg'] ?>;color:<?= $reg['cor'] ?>">
        <i class="ph <?= $cargo['icon'] ?>"></i>
      </div>
      <div>
        <div class="cargo-name"><?= htmlspecialchars($cargo['label']) ?></div>
        <?php if ($ativo): ?>
        <div class="cargo-count"><strong><?= $cargo['count'] ?></strong> parlamentar<?= $cargo['count'] !== 1 ? 'es' : '' ?></div>
        <?php elseif (!$temProjeto): ?>
        <div class="cargo-coming">Não disponível</div>
        <?php else: ?>
        <div class="cargo-coming">Dados em breve</div>
        <?php endif; ?>
      </div>
      <?php if ($ativo): ?><i class="ph ph-arrow-right cargo-arrow"></i><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Assembleia estadual -->
  <?php if (!empty($extrasPorCargo['estadual'])): ?>
  <div class="section-title">Âmbito estadual</div>
  <div class="cargo-grid">
    <?php foreach ($extrasPorCargo['estadual'] as $fe):
      $temProjeto = isset($projetosBySource[$fe['source_key']]);
      $ativo      = $temProjeto && $fe['count'] > 0;
    ?>
    <div class="cargo-card <?= $ativo ? '' : 'disabled' ?>"
         <?php if ($ativo): ?>onclick="selecionarCargo('<?= htmlspecialchars($fe['source_key']) ?>','<?= $fe['apply_uf'] ? '1' : '0' ?>')"<?php endif; ?>>
      <div class="cargo-icon" style="background:<?= $reg['bg'] ?>;color:<?= $reg['cor'] ?>">
        <i class="ph ph-bank"></i>
      </div>
      <div>
        <div class="cargo-name"><?= htmlspecialchars($fe['label']) ?></div>
        <?php if ($ativo): ?>
        <div class="cargo-count"><strong><?= $fe['count'] ?></strong> parlamentar<?= $fe['count'] !== 1 ? 'es' : '' ?></div>
        <?php else: ?>
        <div class="cargo-coming">Não disponível</div>
        <?php endif; ?>
      </div>
      <?php if ($ativo): ?><i class="ph ph-arrow-right cargo-arrow"></i><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Câmaras municipais -->
  <?php if (!empty($extrasPorCargo['municipal'])): ?>
  <div class="section-title">Câmaras municipais</div>
  <div class="municipal-list">
    <?php foreach ($extrasPorCargo['municipal'] as $fe):
      $temProjeto = isset($projetosBySource[$fe['source_key']]);
      $ativo      = $temProjeto && $fe['count'] > 0;
    ?>
    <div class="mun-item <?= $ativo ? '' : 'disabled' ?>" style="<?= !$ativo ? 'opacity:.45;cursor:not-allowed' : '' ?>"
         <?php if ($ativo): ?>onclick="selecionarCargo('<?= htmlspecialchars($fe['source_key']) ?>','0')"<?php endif; ?>>
      <div class="mun-item-info">
        <div class="mun-icon"><i class="ph ph-map-pin"></i></div>
        <div>
          <div class="mun-name"><?= htmlspecialchars($fe['label']) ?></div>
          <?php if ($ativo): ?>
          <div class="mun-count"><?= $fe['count'] ?> vereador<?= $fe['count'] !== 1 ? 'es' : '' ?></div>
          <?php else: ?>
          <div class="mun-count" style="color:#d1d5db">Não disponível</div>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($ativo): ?><i class="ph ph-caret-right mun-arrow"></i><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Cargos desativados -->
  <div class="section-title">Em breve</div>
  <div class="cargo-grid">
    <?php foreach ($desativados as $d): ?>
    <div class="cargo-card disabled">
      <div class="cargo-icon" style="background:#f9fafb;color:#d1d5db">
        <i class="ph <?= $d['icon'] ?>"></i>
      </div>
      <div>
        <div class="cargo-name" style="color:#9ca3af"><?= htmlspecialchars($d['label']) ?></div>
        <div class="cargo-coming">Dados em breve</div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<form id="frmSelecionar" method="POST" action="<?= BASE_PATH ?>/estados/<?= htmlspecialchars($uf) ?>/selecionar" style="display:none">
  <input type="hidden" name="_token"     value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="source_key" id="selSourceKey" value="">
  <input type="hidden" name="apply_uf"   id="selApplyUf"   value="">
</form>

<script>
function selecionarCargo(sourceKey, applyUf) {
  document.getElementById('selSourceKey').value = sourceKey;
  document.getElementById('selApplyUf').value   = applyUf;

  const form = document.getElementById('frmSelecionar');
  const fd   = new FormData(form);

  fetch(form.action, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        window.location.href = data.redirect;
      } else {
        alert(data.error || 'Erro ao selecionar.');
      }
    })
    .catch(() => alert('Erro de conexão.'));
}
</script>
