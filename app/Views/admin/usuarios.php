<?php
$paleta = ['#16a34a','#2563eb','#9333ea','#ea580c','#0891b2','#db2777','#ca8a04'];
$lvls   = ['SuperAdmin','Administrador','Gestor','Analista','Visualizador'];
function uAvatar(string $nome, array $paleta): array {
    $partes  = array_filter(explode(' ', trim($nome)));
    $ini     = strtoupper(substr($partes[0] ?? 'U', 0, 1) . substr(end($partes) ?? '', 0, 1));
    $cor     = $paleta[abs(crc32($nome)) % count($paleta)];
    return [$ini, $cor];
}
?>

<?php if (Auth::nivel() === 0): ?>
<a href="<?= BASE_PATH ?>/admin" style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--muted);margin-bottom:20px;transition:color .15s" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--muted)'">
  <i class="ph ph-arrow-left" style="font-size:15px"></i> Voltar para Configurações
</a>
<?php endif; ?>

<div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px">
  <div>
    <h1 id="pageTitle" style="font-family:'Playfair Display',serif;font-size:22px;font-weight:800;line-height:1.1">Membros da Equipe</h1>
    <?php if (($ctx['id'] ?? null) === null && Auth::isSuperAdmin()): ?>
    <p id="pageSub" style="font-size:13px;color:var(--muted);margin-top:4px">Administradores do sistema (SuperAdmin e Administrador sem cliente vinculado).</p>
    <?php else: ?>
    <p id="pageSub" style="font-size:13px;color:var(--muted);margin-top:4px">Gerencie os usuários de <strong><?= htmlspecialchars($ctx['nome'] ?? '') ?></strong>.</p>
    <?php endif; ?>
  </div>
  <?php if (Auth::nivel() <= 1): ?>
  <button class="btn-primary" onclick="abrirModalUsuario()" style="display:flex;align-items:center;gap:8px">
    <i class="ph ph-user-plus" style="font-size:16px"></i> Adicionar Usuário
  </button>
  <?php endif; ?>
</div>

<div class="mem-table-wrap">
  <?php if (empty($usuarios)): ?>
    <div class="empty-state">Nenhum usuário cadastrado.</div>
  <?php else: ?>
  <table class="mem-table">
    <thead>
      <tr>
        <th>Membro</th>
        <th>E-mail</th>
        <th style="text-align:right">Ações</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($usuarios as $u):
      [$ini, $cor] = uAvatar($u['nome'], $paleta);
      $role = $lvls[(int)$u['nivel']] ?? 'Usuário';
    ?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:12px">
            <div style="width:38px;height:38px;border-radius:50%;background:<?= $cor ?>;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;letter-spacing:.5px">
              <?= htmlspecialchars($ini) ?>
            </div>
            <div>
              <div style="font-weight:600;font-size:14px;color:var(--text)"><?= htmlspecialchars($u['nome']) ?></div>
              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-top:2px"><?= htmlspecialchars($role) ?></div>
            </div>
          </div>
        </td>
        <td style="color:var(--muted);font-size:14px"><?= htmlspecialchars($u['email']) ?></td>
        <td>
          <div style="display:flex;align-items:center;justify-content:flex-end;gap:4px">
            <button title="Redefinir senha" onclick="abrirModalSenha(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['nome'])) ?>')"
              style="width:34px;height:34px;border:none;background:none;display:flex;align-items:center;justify-content:center;border-radius:7px;color:#9ca3af;cursor:pointer;transition:all .15s"
              onmouseover="this.style.background='#f3f4f6';this.style.color='var(--accent)'"
              onmouseout="this.style.background='none';this.style.color='#9ca3af'">
              <i class="ph ph-key" style="font-size:18px"></i>
            </button>
            <?php if ($u['id'] != Auth::id()): ?>
            <form method="POST" action="<?= BASE_PATH ?>/admin/usuarios/deletar" style="display:contents" onsubmit="return confirm('Desativar este usuário?')">
              <input type="hidden" name="_token" value="<?= $csrf ?>">
              <input type="hidden" name="id" value="<?= $u['id'] ?>">
              <button type="submit" title="Desativar usuário"
                style="width:34px;height:34px;border:none;background:none;display:flex;align-items:center;justify-content:center;border-radius:7px;color:#9ca3af;cursor:pointer;transition:all .15s"
                onmouseover="this.style.background='#fef2f2';this.style.color='var(--red)'"
                onmouseout="this.style.background='none';this.style.color='#9ca3af'">
                <i class="ph ph-trash" style="font-size:18px"></i>
              </button>
            </form>
            <?php else: ?>
            <span style="font-size:12px;color:var(--muted);padding:0 8px">você</span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<style>
.mem-table-wrap{background:#fff;border:1px solid var(--border);border-radius:14px;overflow:hidden}
.mem-table{width:100%;border-collapse:collapse;font-size:14px}
.mem-table th{padding:12px 20px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);border-bottom:1px solid var(--border);background:#fff;text-align:left}
.mem-table td{padding:14px 20px;border-bottom:1px solid var(--border);vertical-align:middle}
.mem-table tr:last-child td{border-bottom:none}
.mem-table tr:hover td{background:#fafafa}
</style>

<!-- Modal: Adicionar Usuário -->
<div id="modalUsuario" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:440px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.2);margin:16px">
    <button onclick="fecharModalUsuario()" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:22px;color:var(--muted);cursor:pointer;line-height:1;display:flex;align-items:center">
      <i class="ph ph-x"></i>
    </button>
    <h2 style="font-family:'Playfair Display',serif;font-size:20px;font-weight:800;margin-bottom:20px">Adicionar Usuário</h2>

    <form method="POST" action="<?= BASE_PATH ?>/admin/usuarios/novo">
      <input type="hidden" name="_token" value="<?= $csrf ?>">

      <?php if (Auth::isSuperAdmin() && ($ctx['id'] ?? null) === null): ?>
      <!-- SuperAdmin em visão global: escolhe o cliente -->
      <div class="form-group">
        <label>Cliente</label>
        <select name="cliente_id" class="form-select">
          <option value="">SuperAdmin (sem cliente)</option>
          <?php foreach ((new Cliente())->allAtivos() as $c): ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php elseif ($ctx['id'] ?? null): ?>
      <!-- Contexto fixo por projeto ativo -->
      <input type="hidden" name="cliente_id" value="<?= (int)$ctx['id'] ?>">
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
          <?php if (Auth::isSuperAdmin() && ($ctx['id'] ?? null) === null): ?>
          <option value="0">0 — SuperAdmin</option>
          <?php endif; ?>
          <?php if (Auth::nivel() <= 1): ?>
          <option value="1">1 — ClienteAdmin</option>
          <?php endif; ?>
          <option value="2">2 — Gestor</option>
          <option value="3">3 — Analista</option>
          <option value="4" selected>4 — Visualizador</option>
        </select>
      </div>

      <button type="submit" class="btn-primary" style="width:100%;margin-top:8px">Adicionar Usuário</button>
    </form>
  </div>
</div>

<!-- Modal: Redefinir Senha -->
<div id="modalSenha" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px;width:100%;max-width:400px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.2);margin:16px">
    <button onclick="fecharModalSenha()" style="position:absolute;top:16px;right:16px;border:none;background:none;font-size:22px;color:var(--muted);cursor:pointer;line-height:1;display:flex;align-items:center">
      <i class="ph ph-x"></i>
    </button>
    <h2 style="font-family:'Playfair Display',serif;font-size:20px;font-weight:800;margin-bottom:4px">Redefinir Senha</h2>
    <p id="modalSenhaNome" style="font-size:13px;color:var(--muted);margin-bottom:20px"></p>

    <form method="POST" action="<?= BASE_PATH ?>/admin/usuarios/senha">
      <input type="hidden" name="_token" value="<?= $csrf ?>">
      <input type="hidden" name="id" id="modalSenhaId">
      <div class="form-group">
        <label>Nova senha</label>
        <input type="password" name="senha" required placeholder="Mínimo 8 caracteres">
      </div>
      <button type="submit" class="btn-primary" style="width:100%;margin-top:8px">Salvar nova senha</button>
    </form>
  </div>
</div>

<script>
function abrirModalUsuario() {
  document.getElementById('modalUsuario').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function fecharModalUsuario() {
  document.getElementById('modalUsuario').style.display = 'none';
  document.body.style.overflow = '';
}
function abrirModalSenha(id, nome) {
  document.getElementById('modalSenhaId').value = id;
  document.getElementById('modalSenhaNome').textContent = nome;
  document.getElementById('modalSenha').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function fecharModalSenha() {
  document.getElementById('modalSenha').style.display = 'none';
  document.body.style.overflow = '';
}
['modalUsuario','modalSenha'].forEach(function(id) {
  document.getElementById(id).addEventListener('click', function(e) {
    if (e.target === this) { this.style.display = 'none'; document.body.style.overflow = ''; }
  });
});
</script>
