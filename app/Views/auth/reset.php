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
    <h1 class="auth-heading">Nova senha</h1>
    <p class="auth-sub">Defina uma nova senha para acessar a plataforma.</p>

    <?php if ($error): ?>
      <div class="error-msg">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($token): ?>
      <form method="POST" action="<?= BASE_PATH ?>/redefinir-senha">
        <input type="hidden" name="_token" value="<?= $csrf ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div class="input-group">
          <label for="senha">Nova senha</label>
          <input type="password" id="senha" name="senha" required autofocus placeholder="mínimo 6 caracteres" minlength="6">
        </div>

        <div class="input-group">
          <label for="confirma">Confirmar nova senha</label>
          <input type="password" id="confirma" name="confirma" required placeholder="repita a senha">
        </div>

        <button type="submit" class="btn-primary">Salvar nova senha</button>
      </form>
    <?php else: ?>
      <div style="text-align:center;padding:12px 0">
        <p style="color:#6b7280;margin-bottom:20px">Este link é inválido ou já foi utilizado.</p>
        <a href="<?= BASE_PATH ?>/esqueci-senha" class="btn-primary" style="display:inline-block">Solicitar novo link</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="auth-footer">
    <a href="<?= BASE_PATH ?>/login" style="color:var(--accent);font-weight:600">← Voltar ao login</a>
  </div>
</div>
