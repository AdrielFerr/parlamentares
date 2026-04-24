<div class="page-header">
  <h1 class="page-title"><?= $projeto ? 'Editar Projeto' : 'Novo Projeto' ?></h1>
  <a href="<?= BASE_PATH ?>/projetos" class="btn-sm">← Voltar</a>
</div>

<div class="card-plain" style="max-width:560px">
  <?php if ($error): ?>
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="<?= BASE_PATH ?>/projetos/<?= $projeto ? 'editar' : 'novo' ?>">
    <input type="hidden" name="_token" value="<?= $csrf ?>">
    <?php if ($projeto): ?>
    <input type="hidden" name="id" value="<?= $projeto['id'] ?>">
    <?php endif; ?>

    <?php if (Auth::isSuperAdmin() && !$projeto): ?>
    <div class="form-group">
      <label>Cliente</label>
      <select name="cliente_id" class="form-select" required>
        <option value="">Selecione...</option>
        <?php foreach ($clientes as $c): ?>
        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div class="form-group">
      <label>Nome do Projeto</label>
      <input type="text" name="nome" required value="<?= htmlspecialchars($projeto['nome'] ?? '') ?>" placeholder="Ex: Monitoramento Legislativo 2025">
    </div>

    <div class="form-group">
      <label>Fonte Legislativa</label>
      <select name="fonte_id" class="form-select" required>
        <option value="">Selecione...</option>
        <?php foreach ($fontes as $f): ?>
        <option value="<?= $f['id'] ?>" <?= ($projeto['fonte_id'] ?? 0) == $f['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($f['label']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label>Chave OpenAI (API Key)</label>
      <input type="password" name="openai_key" placeholder="sk-proj-... (deixe em branco para manter)">
      <p class="form-hint">Armazenada de forma criptografada. Nunca exposta ao navegador.</p>
    </div>

    <button type="submit" class="btn-primary" style="width:100%;margin-top:8px">
      <?= $projeto ? 'Salvar Alterações' : 'Criar Projeto' ?>
    </button>
  </form>
</div>
