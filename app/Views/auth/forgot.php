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
    <h1 class="auth-heading">Esqueci minha senha</h1>
    <p class="auth-sub">Informe seu e-mail e enviaremos um link para redefinir a senha.</p>

    <?php if ($error): ?>
      <div class="error-msg">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;font-size:14px;margin-bottom:22px;display:flex;align-items:flex-start;gap:10px;line-height:1.5">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?= htmlspecialchars($success) ?>
      </div>
    <?php else: ?>
      <form method="POST" action="<?= BASE_PATH ?>/esqueci-senha">
        <input type="hidden" name="_token" value="<?= $csrf ?>">
        <div class="input-group">
          <label for="email">E-mail cadastrado</label>
          <input type="email" id="email" name="email" required autofocus placeholder="seu@email.com">
        </div>
        <button type="submit" class="btn-primary">Enviar link de redefinição</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="auth-footer">
    <a href="<?= BASE_PATH ?>/login" style="color:var(--accent);font-weight:600">← Voltar ao login</a>
  </div>
</div>
