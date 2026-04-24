<div class="auth-box">
  <div class="auth-logo"><?= APP_NAME ?></div>
  <p class="auth-sub">Inteligência Legislativa</p>

  <?php if ($error): ?>
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="<?= BASE_PATH ?>/login">
    <input type="hidden" name="_token" value="<?= $csrf ?>">

    <label for="email">E-mail</label>
    <input type="email" id="email" name="email" required autofocus placeholder="seu@email.com">

    <label for="senha">Senha</label>
    <input type="password" id="senha" name="senha" required placeholder="••••••••">

    <button type="submit" class="btn-primary">Entrar</button>
  </form>
</div>
