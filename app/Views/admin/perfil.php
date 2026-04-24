<?php
$user      = Auth::user();
$userNome  = $user['nome']  ?? '';
$userEmail = $user['email'] ?? '';
$lvls      = ['SuperAdmin','Administrador','Gestor','Analista','Visualizador'];
$userRole  = $lvls[Auth::nivel()] ?? 'Usuário';

$partes   = array_filter(explode(' ', trim($userNome)));
$iniciais = strtoupper(substr($partes[0] ?? 'U', 0, 1) . substr(end($partes) ?? '', 0, 1));
$paleta   = ['#16a34a','#2563eb','#9333ea','#ea580c','#0891b2','#db2777','#ca8a04'];
$corAv    = $paleta[abs(crc32($userNome)) % count($paleta)];
?>

<div style="margin-bottom:24px">
  <h2 id="pageTitle" style="font-family:'Playfair Display',serif;font-size:22px;font-weight:800;line-height:1.1">Meu Perfil</h2>
  <p id="pageSub" style="font-size:13px;color:var(--muted);margin-top:4px">Atualize suas informações pessoais e senha</p>
</div>

<div style="max-width:520px">

  <!-- Avatar -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:14px;padding:24px;margin-bottom:20px;display:flex;align-items:center;gap:20px">
    <div style="width:64px;height:64px;border-radius:50%;background:<?= $corAv ?>;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;color:#fff;flex-shrink:0">
      <?= htmlspecialchars($iniciais) ?>
    </div>
    <div>
      <div style="font-size:17px;font-weight:700"><?= htmlspecialchars($userNome) ?></div>
      <div style="font-size:13px;color:var(--muted);margin-top:2px"><?= htmlspecialchars($userEmail) ?></div>
      <span style="display:inline-block;margin-top:6px;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:700;background:var(--accent-light);color:var(--accent)"><?= htmlspecialchars($userRole) ?></span>
    </div>
  </div>

  <!-- Form -->
  <div style="background:#fff;border:1px solid var(--border);border-radius:14px;padding:24px">

    <?php if ($error ?? null): ?>
      <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success ?? null): ?>
      <div style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;border-radius:8px;padding:9px 13px;font-size:13px;margin-bottom:16px"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_PATH ?>/perfil">
      <input type="hidden" name="_token" value="<?= $csrf ?>">

      <div class="form-group">
        <label>Nome completo</label>
        <input type="text" name="nome" value="<?= htmlspecialchars($userNome) ?>" placeholder="Seu nome">
      </div>

      <div class="form-group">
        <label>E-mail</label>
        <input type="email" name="email" value="<?= htmlspecialchars($userEmail) ?>" placeholder="seu@email.com">
      </div>

      <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">
      <p style="font-size:13px;font-weight:600;color:var(--muted);margin-bottom:14px">Alterar senha <span style="font-weight:400">(deixe em branco para manter a atual)</span></p>

      <div class="form-group">
        <label>Nova senha</label>
        <input type="password" name="senha" placeholder="Mínimo 6 caracteres">
      </div>

      <div class="form-group">
        <label>Confirmar nova senha</label>
        <input type="password" name="confirma" placeholder="Repita a nova senha">
      </div>

      <button type="submit" class="btn-primary" style="width:100%;margin-top:8px">Salvar alterações</button>
    </form>
  </div>
</div>
