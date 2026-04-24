<div class="page-header">
  <h1 class="page-title">Novo Cliente</h1>
  <a href="<?= BASE_PATH ?>/admin/clientes" class="btn-sm">← Voltar</a>
</div>

<div class="card-plain" style="max-width:400px">
  <?php if ($error ?? null): ?>
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="<?= BASE_PATH ?>/admin/clientes/novo">
    <input type="hidden" name="_token" value="<?= $csrf ?>">

    <div class="form-group">
      <label>Nome do Cliente</label>
      <input type="text" name="nome" required placeholder="Ex: Prefeitura de João Pessoa">
    </div>

    <button type="submit" class="btn-primary" style="width:100%;margin-top:8px">Criar Cliente</button>
  </form>
</div>
