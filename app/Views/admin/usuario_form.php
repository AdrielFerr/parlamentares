<div class="page-header">
  <h1 class="page-title">Novo Usuário</h1>
  <a href="<?= BASE_PATH ?>/admin/usuarios" class="btn-sm">← Voltar</a>
</div>

<div class="card-plain" style="max-width:480px">
  <?php if ($error ?? null): ?>
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="<?= BASE_PATH ?>/admin/usuarios/novo">
    <input type="hidden" name="_token" value="<?= $csrf ?>">

    <?php if (Auth::isSuperAdmin()): ?>
    <div class="form-group">
      <label>Cliente</label>
      <select name="cliente_id" class="form-select">
        <option value="">SuperAdmin (sem cliente)</option>
        <?php foreach ($clientes as $c): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div class="form-group">
      <label>Nome completo</label>
      <input type="text" name="nome" required placeholder="João da Silva">
    </div>

    <div class="form-group">
      <label>E-mail</label>
      <input type="email" name="email" required placeholder="joao@empresa.com.br">
    </div>

    <div class="form-group">
      <label>Senha</label>
      <input type="password" name="senha" required placeholder="Mínimo 8 caracteres">
    </div>

    <div class="form-group">
      <label>Nível de acesso</label>
      <select name="nivel" class="form-select">
        <?php if (Auth::isSuperAdmin()): ?>
        <option value="0">0 — SuperAdmin</option>
        <option value="1">1 — ClienteAdmin</option>
        <?php endif; ?>
        <option value="2">2 — Gestor</option>
        <option value="3">3 — Analista</option>
        <option value="4" selected>4 — Visualizador</option>
      </select>
    </div>

    <button type="submit" class="btn-primary" style="width:100%;margin-top:8px">Criar Usuário</button>
  </form>
</div>
