<?php
$isSuperAdmin  = Auth::isSuperAdmin();
$isGlobalView  = $isSuperAdmin && ($ctx['id'] ?? null) === null;
$clienteNome   = $ctx['nome'] ?? 'Sistema';
?>

<div style="margin-bottom:24px">
  <h2 style="font-family:'Playfair Display',serif;font-size:22px;font-weight:800;line-height:1.1">Visão Geral</h2>
  <p style="font-size:13px;color:var(--muted);margin-top:4px">
    <?= $isGlobalView
        ? 'Visão global do sistema — todos os clientes e projetos'
        : 'Gerencie usuários e projetos de ' . htmlspecialchars($clienteNome) ?>
  </p>
</div>

<div class="cfg-grid" style="margin-bottom:36px">
  <a href="<?= BASE_PATH ?>/admin/usuarios" class="cfg-card">
    <div class="cfg-icon"><i class="ph ph-users" style="font-size:24px;color:var(--accent)"></i></div>
    <div class="cfg-body">
      <div class="cfg-title">Membros da Equipe</div>
      <div class="cfg-desc">
        <?= $isGlobalView
            ? 'Gerencie todos os usuários do sistema.'
            : 'Gerencie os usuários de ' . htmlspecialchars($clienteNome) . '.' ?>
      </div>
    </div>
    <div class="cfg-arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6"/></svg></div>
  </a>

  <a href="<?= BASE_PATH ?>/perfil" class="cfg-card">
    <div class="cfg-icon"><i class="ph ph-user-circle" style="font-size:24px;color:var(--accent)"></i></div>
    <div class="cfg-body">
      <div class="cfg-title">Meu Perfil</div>
      <div class="cfg-desc">Atualize seu nome, e-mail e senha de acesso.</div>
    </div>
    <div class="cfg-arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6"/></svg></div>
  </a>

  <?php if ($isGlobalView): ?>
  <a href="<?= BASE_PATH ?>/admin/clientes" class="cfg-card">
    <div class="cfg-icon"><i class="ph ph-buildings" style="font-size:24px;color:var(--accent)"></i></div>
    <div class="cfg-body">
      <div class="cfg-title">Clientes</div>
      <div class="cfg-desc">Gerencie os clientes e suas organizações cadastradas no sistema.</div>
    </div>
    <div class="cfg-arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 18l6-6-6-6"/></svg></div>
  </a>
  <?php endif; ?>
</div>

