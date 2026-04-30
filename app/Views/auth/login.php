<div class="login-outer">
  <div class="login-logo">
    <?php $_loginLogo = Configuracao::logoUrl(null); ?>
    <?php if ($_loginLogo): ?>
      <img src="<?= BASE_PATH . htmlspecialchars($_loginLogo) ?>" alt="Logo" style="max-height:100px;max-width:300px;object-fit:contain">
    <?php else: ?>
      <div class="logo-icon">K</div>
      <span class="logo-name"><?= htmlspecialchars(APP_NAME) ?></span>
    <?php endif; ?>
  </div>

  <div class="auth-card">
    <h1 class="auth-heading">Bem-vindo de volta</h1>
    <p class="auth-sub">Acesse sua plataforma de inteligência legislativa</p>

    <?php if ($error): ?>
      <div class="error-msg">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_PATH ?>/login">
      <input type="hidden" name="_token" value="<?= $csrf ?>">

      <div class="input-group">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required autofocus placeholder="seu@email.com">
      </div>

      <div class="input-group">
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" required placeholder="••••••••">
      </div>

      <button type="submit" class="btn-primary">Entrar na plataforma</button>
    </form>
  </div>
  <div class="auth-footer">KeekConecta — Inteligência Legislativa</div>
</div>
