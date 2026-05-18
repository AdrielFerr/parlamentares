<?php /* View: dashboard externo em iframe — token nunca aparece em links */ ?>
<style>
body.dashboard-embed-page .main-content{
  padding:0;
}
.dashboard-embed-shell{
  background:var(--card);
  border:0;
  border-radius:0;
  overflow:hidden;
  height:calc(100vh - 64px);
  position:relative;
}
.dashboard-embed-shell iframe{
  width:100%;
  height:100%;
  border:0;
  display:block;
}
@media(max-width:768px){
  .dashboard-embed-shell{
    height:calc(100vh - 64px);
  }
}
</style>
<script>
document.body.classList.add('dashboard-embed-page');
</script>

<div class="dashboard-embed-shell">
  <iframe src="<?= htmlspecialchars($iframeSrc, ENT_QUOTES) ?>"
          allow="fullscreen"
          loading="lazy"
          title="<?= $nome ?>">
  </iframe>
</div>
