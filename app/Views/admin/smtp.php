<?php
$smtpHost      = htmlspecialchars($cfg['smtp_host']           ?? '');
$smtpPort      = htmlspecialchars($cfg['smtp_port']           ?? '587');
$smtpEnc       = $cfg['smtp_encryption']                      ?? 'tls';
$smtpUser      = htmlspecialchars($cfg['smtp_user']           ?? '');
$smtpFromEmail = htmlspecialchars($cfg['smtp_from_email']     ?? '');
$smtpFromName  = htmlspecialchars($cfg['smtp_from_name']      ?? APP_NAME);
$resetAssunto  = htmlspecialchars($cfg['email_reset_assunto'] ?? '');
$resetCorpo    = $cfg['email_reset_corpo']                    ?? '';
?>

<style>
.smtp-page{max-width:760px;margin:0 auto;padding:32px 24px}
.smtp-section{background:#fff;border:1px solid var(--border);border-radius:16px;padding:28px 32px;margin-bottom:24px}
.smtp-section-title{font-size:15px;font-weight:700;color:var(--text);margin-bottom:4px}
.smtp-section-sub{font-size:13px;color:var(--muted);margin-bottom:22px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px}
.form-row.single{grid-template-columns:1fr}
.form-group{display:flex;flex-direction:column;gap:6px}
.form-group label{font-size:13px;font-weight:600;color:var(--text)}
.form-group input,.form-group select,.form-group textarea{padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:13.5px;font-family:'Inter',sans-serif;background:#fafafa;color:var(--text);outline:none;transition:border-color .18s,background .18s}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(22,163,74,.08)}
.form-group textarea{resize:vertical;min-height:200px;font-size:13px;line-height:1.5}
.form-group .hint{font-size:12px;color:var(--muted);margin-top:3px}
.btn-save{padding:10px 24px;background:var(--accent);color:#fff;border:none;border-radius:9px;font-size:14px;font-weight:700;cursor:pointer;transition:background .2s}
.btn-save:hover{background:var(--accent-dark)}
.btn-test{padding:10px 20px;background:#fff;color:var(--accent);border:1.5px solid var(--accent);border-radius:9px;font-size:14px;font-weight:700;cursor:pointer;transition:background .2s,color .2s}
.btn-test:hover{background:var(--accent-light)}
.btn-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:8px}
.alert{border-radius:9px;padding:12px 16px;font-size:14px;margin-bottom:20px;display:flex;align-items:center;gap:10px}
.alert-ok{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
.alert-err{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.placeholder-tag{display:inline-block;background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;border-radius:5px;padding:2px 8px;font-size:12px;font-family:monospace;margin:2px}
#testar-result{display:none;margin-top:12px;border-radius:9px;padding:10px 14px;font-size:13px;font-weight:600}
</style>

<div class="smtp-page">

  <div style="margin-bottom:24px">
    <div style="font-size:22px;font-weight:800;color:var(--text)">E-mail & Redefinição de Senha</div>
    <div style="font-size:14px;color:var(--muted);margin-top:4px">Configure o servidor SMTP e personalize o e-mail de recuperação de senha.</div>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-ok">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <?= htmlspecialchars($msg) ?>
    </div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-err">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="<?= BASE_PATH ?>/admin/smtp">
    <input type="hidden" name="_token" value="<?= $csrf ?>">

    <!-- Configurações SMTP -->
    <div class="smtp-section">
      <div class="smtp-section-title">Configurações SMTP</div>
      <div class="smtp-section-sub">Credenciais do servidor de e-mail. Compatível com Gmail, Outlook, Sendgrid e qualquer provedor SMTP.</div>

      <div class="form-row">
        <div class="form-group">
          <label>Servidor (host)</label>
          <input type="text" name="smtp_host" value="<?= $smtpHost ?>" placeholder="smtp.gmail.com">
        </div>
        <div class="form-group">
          <label>Porta</label>
          <input type="number" name="smtp_port" value="<?= $smtpPort ?>" placeholder="587">
          <span class="hint">587 = TLS (STARTTLS) · 465 = SSL · 25 = sem criptografia</span>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Criptografia</label>
          <select name="smtp_encryption">
            <option value="tls"  <?= $smtpEnc === 'tls'  ? 'selected' : '' ?>>TLS / STARTTLS (recomendado)</option>
            <option value="ssl"  <?= $smtpEnc === 'ssl'  ? 'selected' : '' ?>>SSL</option>
            <option value="none" <?= $smtpEnc === 'none' ? 'selected' : '' ?>>Nenhuma</option>
          </select>
        </div>
        <div class="form-group">
          <label>Usuário (e-mail de autenticação)</label>
          <input type="email" name="smtp_user" value="<?= $smtpUser ?>" placeholder="seuemail@gmail.com">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Senha / App Password</label>
          <input type="password" name="smtp_pass" placeholder="<?= $smtpUser ? '••••••••••••••••' : 'senha ou app password' ?>">
          <span class="hint"><?= $smtpUser ? 'Deixe em branco para manter a senha atual.' : 'Para Gmail: use uma "Senha de app" (2FA ativado).' ?></span>
        </div>
        <div class="form-group">
          <label>Nome do remetente</label>
          <input type="text" name="smtp_from_name" value="<?= $smtpFromName ?>" placeholder="<?= htmlspecialchars(APP_NAME) ?>">
        </div>
      </div>

      <div class="form-row single">
        <div class="form-group">
          <label>E-mail do remetente (From)</label>
          <input type="email" name="smtp_from_email" value="<?= $smtpFromEmail ?>" placeholder="noreply@seudominio.com.br">
          <span class="hint">No Gmail, deve ser o mesmo e-mail de autenticação ou um alias verificado.</span>
        </div>
      </div>

      <div class="btn-row">
        <button type="submit" class="btn-save">Salvar configurações SMTP</button>
        <button type="button" class="btn-test" onclick="testarSmtp()">Testar envio</button>
      </div>
      <div id="testar-result"></div>
    </div>

    <!-- Template do e-mail -->
    <div class="smtp-section">
      <div class="smtp-section-title">Template do E-mail de Redefinição</div>
      <div class="smtp-section-sub">
        Personalize o assunto e o corpo do e-mail enviado quando um usuário solicitar redefinição de senha.
        Variáveis disponíveis:
        <span class="placeholder-tag">{{nome}}</span>
        <span class="placeholder-tag">{{link}}</span>
        <span class="placeholder-tag">{{expira}}</span>
      </div>

      <div class="form-row single">
        <div class="form-group">
          <label>Assunto do e-mail</label>
          <input type="text" name="email_reset_assunto" value="<?= $resetAssunto ?>" placeholder="Redefinição de Senha — <?= htmlspecialchars(APP_NAME) ?>">
        </div>
      </div>

      <div class="form-row single">
        <div class="form-group">
          <label>Corpo do e-mail (HTML)</label>
          <textarea name="email_reset_corpo" rows="12"><?= htmlspecialchars($resetCorpo ?: Mailer::defaultResetTemplate()) ?></textarea>
          <span class="hint">Suporte a HTML. Use as variáveis acima para personalizar a mensagem.</span>
        </div>
      </div>

      <div class="btn-row">
        <button type="submit" class="btn-save">Salvar template</button>
        <button type="button" class="btn-test" onclick="previewTemplate()">Visualizar preview</button>
      </div>
    </div>

  </form>
</div>

<script>
async function testarSmtp() {
  const btn = document.querySelector('.btn-test');
  const res = document.getElementById('testar-result');
  res.style.display = 'none';
  btn.disabled = true;
  btn.textContent = 'Enviando...';

  const form = document.querySelector('form');
  const fd   = new FormData(form);
  fd.set('_action', 'testar');

  try {
    const r = await fetch('<?= BASE_PATH ?>/admin/smtp/testar', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams(fd),
    });
    const j = await r.json();
    res.style.display = 'block';
    res.style.background = j.ok ? '#f0fdf4' : '#fef2f2';
    res.style.color       = j.ok ? '#15803d' : '#dc2626';
    res.style.border      = j.ok ? '1px solid #bbf7d0' : '1px solid #fecaca';
    res.textContent       = j.msg;
  } catch(e) {
    res.style.display = 'block';
    res.style.background = '#fef2f2';
    res.style.color = '#dc2626';
    res.style.border = '1px solid #fecaca';
    res.textContent = 'Erro de comunicação.';
  }
  btn.disabled = false;
  btn.textContent = 'Testar envio';
}

function previewTemplate() {
  const body = document.querySelector('textarea[name="email_reset_corpo"]').value;
  const preview = body
    .replace(/\{\{nome\}\}/g, '<?= htmlspecialchars(Auth::user()['nome'] ?? 'Usuário') ?>')
    .replace(/\{\{link\}\}/g, window.location.origin + '<?= BASE_PATH ?>/redefinir-senha?token=EXEMPLO123')
    .replace(/\{\{expira\}\}/g, '1 hora');
  const win = window.open('', '_blank');
  win.document.write(preview);
  win.document.close();
}
</script>
