<div class="page-header">
  <h1 class="page-title">Clientes</h1>
  <a href="<?= BASE_PATH ?>/admin/clientes/novo" class="btn-primary">+ Novo Cliente</a>
</div>

<?php if (empty($clientes)): ?>
  <div class="empty-state">Nenhum cliente cadastrado.</div>
<?php else: ?>
<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Nome</th>
        <th>Projetos</th>
        <th>Ações</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($clientes as $c): ?>
      <tr>
        <td style="font-weight:600"><?= htmlspecialchars($c['nome']) ?></td>
        <td><?= $cModel->projetosCount($c['id']) ?></td>
        <td>
          <form method="POST" action="<?= BASE_PATH ?>/admin/clientes/deletar" style="display:inline" onsubmit="return confirm('Arquivar cliente?')">
            <input type="hidden" name="_token" value="<?= $csrf ?>">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button type="submit" class="btn-danger">Arquivar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
