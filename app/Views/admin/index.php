<?php
$isSuperAdmin = Auth::isSuperAdmin();
$isGlobalView = $isSuperAdmin && ($ctx['id'] ?? null) === null;
$clienteNome  = $ctx['nome'] ?? 'Sistema';
?>

<div class="cfg-hub-head">
  <div class="cfg-hub-title">Configurações</div>
  <p class="cfg-hub-sub">
    <?= $isGlobalView
        ? 'Visão global do sistema — gerencie usuários, clientes e aparência da plataforma'
        : 'Gerencie usuários e configurações de ' . htmlspecialchars($clienteNome) ?>
  </p>
</div>

<div class="hub-grid">

  <a href="<?= BASE_PATH ?>/admin/usuarios" class="hub-card">
    <div class="hub-card-icon" style="background:#eff6ff">
      <i class="ph ph-users" style="color:#2563eb"></i>
    </div>
    <div class="hub-card-body">
      <div class="hub-card-title">Membros da Equipe</div>
      <div class="hub-card-desc">
        <?= $isGlobalView
            ? 'Gerencie todos os usuários cadastrados no sistema.'
            : 'Gerencie os usuários de ' . htmlspecialchars($clienteNome) . '.' ?>
      </div>
    </div>
  </a>

  <a href="<?= BASE_PATH ?>/perfil" class="hub-card">
    <div class="hub-card-icon" style="background:#f0fdfa">
      <i class="ph ph-user-circle" style="color:#0891b2"></i>
    </div>
    <div class="hub-card-body">
      <div class="hub-card-title">Meu Perfil</div>
      <div class="hub-card-desc">Atualize seu nome, e-mail e senha de acesso à plataforma.</div>
    </div>
  </a>

  <?php if ($isGlobalView): ?>
  <a href="<?= BASE_PATH ?>/admin/clientes" class="hub-card">
    <div class="hub-card-icon" style="background:#fff7ed">
      <i class="ph ph-buildings" style="color:#ea580c"></i>
    </div>
    <div class="hub-card-body">
      <div class="hub-card-title">Clientes</div>
      <div class="hub-card-desc">Gerencie os clientes e suas organizações cadastradas no sistema.</div>
    </div>
  </a>
  <?php endif; ?>

  <?php if ($isGlobalView): ?>
  <a href="<?= BASE_PATH ?>/admin/aparencia" class="hub-card">
    <div class="hub-card-icon" style="background:#faf5ff">
      <i class="ph ph-paint-brush" style="color:#7c3aed"></i>
    </div>
    <div class="hub-card-body">
      <div class="hub-card-title">Aparência</div>
      <div class="hub-card-desc">Personalize a logo e a cor principal da plataforma para todos os clientes.</div>
    </div>
  </a>
  <?php endif; ?>

</div>
