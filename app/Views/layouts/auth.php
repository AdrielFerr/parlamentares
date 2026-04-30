<?php
$_cssVarsAuth = Configuracao::getCssVars(null);
$_logoUrlAuth = Configuracao::logoUrl(null);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(APP_NAME) ?> — Acesso</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#F0F4F8;
  --card:#fff;
  --accent:#16a34a;
  --accent-light:#f0fdf4;
  --accent-dark:#15803d;
  --text:#111827;
  --muted:#6B7280;
  --border:#E5E7EB;
  --red:#DC2626;
  <?= $_cssVarsAuth ?>
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.login-outer{width:100%;max-width:440px;display:flex;flex-direction:column;align-items:center;gap:0}
.login-logo{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:32px;min-height:100px}
.logo-icon{width:44px;height:44px;background:var(--accent);border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:20px;font-family:'Playfair Display',serif;flex-shrink:0;box-shadow:0 4px 12px rgba(22,163,74,.3)}
.logo-name{font-family:'Playfair Display',serif;font-size:26px;font-weight:800;color:var(--text);letter-spacing:-.5px}
.auth-card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:40px 36px 32px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.08)}
.auth-heading{font-family:'Playfair Display',serif;font-size:22px;font-weight:800;color:var(--text);margin-bottom:6px}
.auth-sub{font-size:14px;color:var(--muted);margin-bottom:26px;line-height:1.5}
label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--text)}
.input-group{margin-bottom:18px}
input[type=email],input[type=password],input[type=text]{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;font-family:'Inter',sans-serif;outline:none;background:#fafafa;transition:border-color .2s,background .2s;color:var(--text)}
input:focus{border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(22,163,74,.08)}
.btn-primary{width:100%;padding:13px;background:var(--accent);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;transition:background .2s;margin-top:4px;letter-spacing:-.1px}
.btn-primary:hover{background:var(--accent-dark)}
.error-msg{background:#FEF2F2;color:var(--red);border:1px solid #FECACA;border-radius:8px;padding:12px 16px;font-size:14px;margin-bottom:22px;display:flex;align-items:center;gap:8px}
.auth-footer{text-align:center;font-size:13px;color:var(--muted);margin-top:22px;line-height:1.5}
</style>
</head>
<body>
<?= $content ?>
</body>
</html>
