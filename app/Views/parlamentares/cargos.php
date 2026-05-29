<?php /* $projeto, $cargos, $uf disponíveis */ ?>
<style>
/* ── Wrapper ── */
.cargos-wrap{max-width:960px}

/* ── Cabeçalho ── */
.cargos-header{margin-bottom:30px}
.cargos-title{font-size:24px;font-weight:800;color:#111827;margin-bottom:5px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.cargos-uf-tag{font-size:12px;font-weight:700;background:var(--accent-light);color:var(--accent-dark);padding:3px 11px;border-radius:20px;flex-shrink:0}
.cargos-sub{font-size:14px;color:#6b7280}

/* ── Grade ── */
.cargos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
@media(max-width:640px){.cargos-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:400px){.cargos-grid{grid-template-columns:1fr}}

/* ── Card base ── */
.cargo-card{border-radius:20px;padding:22px 20px;display:flex;align-items:center;gap:16px;position:relative;transition:box-shadow .18s,transform .18s,border-color .18s;overflow:hidden}
.cargo-disponivel{background:#fff;border:1.5px solid #e5e7eb;cursor:pointer}
.cargo-disponivel:hover{border-color:transparent;box-shadow:0 8px 28px rgba(0,0,0,.11);transform:translateY(-2px)}
.cargo-andamento{background:#f9fafb;border:1.5px dashed #e2e8f0;cursor:default}

/* ── Ícone ── */
.cargo-icon{width:56px;height:56px;border-radius:15px;display:flex;align-items:center;justify-content:center;font-size:27px;flex-shrink:0;transition:opacity .15s}
.cargo-andamento .cargo-icon{opacity:.35}

/* ── Corpo ── */
.cargo-body{flex:1;min-width:0}
.cargo-count{font-size:32px;font-weight:800;line-height:1;margin-bottom:4px}
.cargo-count-nd{font-size:26px;color:#d1d5db}
.cargo-label{font-size:14px;font-weight:700;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px}
.cargo-andamento .cargo-label{color:#9ca3af}
.cargo-desc{font-size:12px;color:#9ca3af;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* ── Seta ── */
.cargo-arrow{font-size:20px;color:#d1d5db;flex-shrink:0;transition:color .15s,transform .15s}
.cargo-disponivel:hover .cargo-arrow{color:var(--accent);transform:translateX(3px)}

/* ── Badge "Em andamento" ── */
.cargo-badge-nd{position:absolute;top:13px;right:13px;display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:700;color:#9ca3af;background:#f3f4f6;padding:3px 9px;border-radius:20px;line-height:1.4}

/* ── Faixa de cor no topo do card disponível ── */
.cargo-disponivel::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:20px 20px 0 0;opacity:0;transition:opacity .18s}
.cargo-disponivel:hover::before{opacity:1}
</style>

<div class="cargos-wrap">

  <div class="cargos-header">
    <h1 class="cargos-title">
      <?php if ($uf): ?>
      <span class="cargos-uf-tag"><?= htmlspecialchars($uf) ?></span>
      <?php endif; ?>
      Cargos políticos
    </h1>
    <p class="cargos-sub">Selecione um cargo para visualizar os parlamentares</p>
  </div>

  <div class="cargos-grid">
    <?php foreach ($cargos as $c):
      $ok  = ($c['status'] === 'disponivel');
      $cor = $c['cor'];
    ?>
    <div class="cargo-card <?= $ok ? 'cargo-disponivel' : 'cargo-andamento' ?>"
         <?= $ok ? 'onclick="window.location.href=\'' . BASE_PATH . '/parlamentares?cargo=' . urlencode($c['key']) . '\'"' : '' ?>>

      <?php if ($ok): ?>
      <style>.cargo-card:hover::before{background:<?= $cor ?>}</style>
      <?php else: ?>
      <div class="cargo-badge-nd"><i class="ph ph-clock"></i> Em andamento</div>
      <?php endif; ?>

      <div class="cargo-icon" style="background:<?= $cor ?>18;color:<?= $cor ?>">
        <i class="ph <?= $c['icon'] ?>"></i>
      </div>

      <div class="cargo-body">
        <?php if ($ok): ?>
        <div class="cargo-count" style="color:<?= $cor ?>"><?= number_format($c['total']) ?></div>
        <?php else: ?>
        <div class="cargo-count cargo-count-nd">—</div>
        <?php endif; ?>
        <div class="cargo-label"><?= htmlspecialchars($c['label']) ?></div>
        <div class="cargo-desc"><?= htmlspecialchars($c['sub']) ?></div>
      </div>

      <?php if ($ok): ?>
      <i class="ph ph-arrow-right cargo-arrow"></i>
      <?php endif; ?>

    </div>
    <?php endforeach; ?>
  </div>

</div>
