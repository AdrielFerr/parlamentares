<?php /* View: dashboard externo em iframe — token nunca aparece em links */ ?>
<div class="page-header">
  <h1 class="page-title"><?= $nome ?></h1>
</div>

<div style="background:#fff;border-radius:12px;border:1px solid #E5E2DB;overflow:hidden;height:calc(100vh - 140px);position:relative;">
  <iframe src="<?= htmlspecialchars($iframeSrc, ENT_QUOTES) ?>"
          style="width:100%;height:100%;border:none;"
          allow="fullscreen"
          loading="lazy"
          title="<?= $nome ?>">
  </iframe>
</div>
