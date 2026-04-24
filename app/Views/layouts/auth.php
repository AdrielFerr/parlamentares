<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>KeekConecta — Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;700&family=Playfair+Display:wght@800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#F7F5F0;--card:#fff;--accent:#1A6B4F;--accent-light:#E8F5EE;--accent-dark:#0F4030;--text:#1A1A1A;--muted:#6B7280;--border:#E5E2DB;--red:#DC2626}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center}
.auth-box{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:40px 36px;width:100%;max-width:400px;box-shadow:0 4px 24px rgba(0,0,0,.06)}
.auth-logo{font-family:'Playfair Display',serif;font-size:28px;font-weight:800;color:var(--accent);margin-bottom:8px}
.auth-sub{font-size:14px;color:var(--muted);margin-bottom:28px}
label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:var(--text)}
input[type=email],input[type=password],input[type=text]{width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:10px;font-size:14px;font-family:inherit;outline:none;margin-bottom:16px;transition:border-color .2s}
input:focus{border-color:var(--accent)}
.btn-primary{width:100%;padding:12px;background:var(--accent);color:#fff;border:none;border-radius:10px;font-size:15px;font-weight:700;font-family:inherit;cursor:pointer;transition:background .2s}
.btn-primary:hover{background:var(--accent-dark)}
.error-msg{background:#FEF2F2;color:var(--red);border:1px solid #FECACA;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:16px}
</style>
</head>
<body>
<?= $content ?>
</body>
</html>
