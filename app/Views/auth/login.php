<div class="login-outer">
  <div class="auth-card">
    <div class="login-logo">
      <?php $_loginLogo = Configuracao::logoUrl(null) ?: '/public/assets/keek-verde.png'; ?>
      <img src="<?= htmlspecialchars(asset_url($_loginLogo)) ?>" alt="Logo">
      <p>Plataforma de inteligência legislativa</p>
    </div>

    <?php if ($error): ?>
      <div class="error-msg">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if (($_GET['reset'] ?? '') === 'ok'): ?>
      <div style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;font-size:14px;margin-bottom:22px;display:flex;align-items:center;gap:8px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        Senha redefinida com sucesso! Faça login com a nova senha.
      </div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_PATH ?>/login">
      <input type="hidden" name="_token" value="<?= $csrf ?>">

      <div class="input-group">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required autofocus placeholder="seu@email.com">
      </div>

      <div class="input-group">
        <label for="senha" style="display:flex;justify-content:space-between;align-items:center">
          Senha
          <a href="<?= BASE_PATH ?>/esqueci-senha" style="font-size:12px;font-weight:500;color:var(--accent)">Esqueci minha senha</a>
        </label>
        <input type="password" id="senha" name="senha" required placeholder="••••••••">
      </div>

      <button type="submit" class="btn-primary">Entrar na plataforma</button>
    </form>
  </div>
</div>
