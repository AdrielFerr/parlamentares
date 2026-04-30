<?php
$isSuperAdmin = Auth::isSuperAdmin();
$clienteNome  = $ctx['nome'] ?? 'Sistema';
$accentAtual  = $cfg['cor_accent'] ?? '#16a34a';
$logoUrl      = $cfg['logo_url']   ?? '';
?>
<style>
.ap-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:820px}
@media(max-width:700px){.ap-grid{grid-template-columns:1fr}}
.ap-card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:28px}
.ap-card-title{font-size:15px;font-weight:700;color:var(--text);margin-bottom:6px;display:flex;align-items:center;gap:8px}
.ap-card-desc{font-size:13px;color:var(--muted);margin-bottom:22px;line-height:1.6}
.swatch-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.swatch{width:32px;height:32px;border-radius:8px;cursor:pointer;border:2px solid transparent;transition:transform .15s,border-color .15s;flex-shrink:0}
.swatch:hover{transform:scale(1.12);border-color:#fff;box-shadow:0 2px 8px rgba(0,0,0,.18)}
.logo-upload-area{border:2px dashed var(--border);border-radius:12px;padding:30px 20px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s}
.logo-upload-area:hover,.logo-upload-area.drag{border-color:var(--accent);background:var(--accent-light)}
.logo-preview-wrap{margin-bottom:18px;text-align:center}
.logo-preview-img{max-height:72px;max-width:200px;object-fit:contain;border-radius:8px;border:1px solid var(--border);padding:8px;background:#fff}
.btn-remove-logo{background:transparent;border:1.5px solid #fecaca;color:var(--red);border-radius:8px;padding:6px 14px;font-size:12px;font-weight:600;font-family:inherit;cursor:pointer;margin-top:10px}
.btn-remove-logo:hover{background:var(--red-light)}
.preview-bar{background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px 20px;display:flex;align-items:center;gap:12px;margin-top:20px}
.preview-logo-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;flex-shrink:0;transition:background .3s}
.preview-btn{padding:8px 16px;border:none;border-radius:8px;color:#fff;font-size:13px;font-weight:700;font-family:inherit;cursor:default;transition:background .3s}
</style>

<!-- Form de remoção de logo — separado do form principal -->
<?php if ($logoUrl): ?>
<form method="POST" action="<?= BASE_PATH ?>/admin/aparencia/logo-remover" id="formRemoverLogo" style="display:none">
  <input type="hidden" name="_token" value="<?= Auth::csrfToken() ?>">
</form>
<?php endif; ?>

<div class="page-header">
  <div>
    <div class="page-title">Aparência</div>
    <p style="font-size:13px;color:var(--muted);margin-top:4px">
      <?= $clienteNome === 'Sistema' ? 'Configurações globais de logo e cor da plataforma' : 'Personalize a identidade visual para ' . htmlspecialchars($clienteNome) ?>
    </p>
  </div>
</div>

<?php if ($success): ?>
<div style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;border-radius:9px;padding:10px 15px;font-size:13px;margin-bottom:20px;max-width:820px;display:flex;align-items:center;gap:8px">
  <i class="ph ph-check-circle" style="font-size:16px"></i> <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="error-msg" style="max-width:820px"><i class="ph ph-warning-circle" style="font-size:16px"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Form principal: cor + upload de logo -->
<form method="POST" action="<?= BASE_PATH ?>/admin/aparencia" enctype="multipart/form-data" id="apForm">
<input type="hidden" name="_token" value="<?= Auth::csrfToken() ?>">
<input type="hidden" name="cor_accent" id="corInput" value="<?= htmlspecialchars($accentAtual) ?>">

<div class="ap-grid">

  <!-- Cor da plataforma -->
  <div class="ap-card">
    <div class="ap-card-title"><i class="ph ph-palette" style="color:var(--accent)"></i> Cor da plataforma</div>
    <div class="ap-card-desc">Define a cor principal dos botões, destaques e sidebar. Escolha uma cor ou selecione uma das sugestões abaixo.</div>

    <label style="font-size:12px;color:var(--muted);font-weight:500;margin-bottom:6px">Cor selecionada</label>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
      <input type="color" id="colorPicker" value="<?= htmlspecialchars($accentAtual) ?>"
             style="width:48px;height:44px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;padding:2px;background:#fff">
      <input type="text" id="hexInput" value="<?= htmlspecialchars($accentAtual) ?>"
             placeholder="#16a34a" maxlength="7"
             style="width:110px;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:14px;font-family:monospace;outline:none;margin:0"
             oninput="syncColor(this.value)">
    </div>

    <div class="swatch-row">
      <?php foreach (['#16a34a','#2563eb','#7c3aed','#dc2626','#ea580c','#0891b2','#db2777','#ca8a04','#475569','#111827'] as $p): ?>
      <div class="swatch" style="background:<?= $p ?>" title="<?= $p ?>" onclick="applyColor('<?= $p ?>')"></div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:22px">
      <div style="font-size:12px;color:var(--muted);font-weight:500;margin-bottom:8px">Pré-visualização</div>
      <div class="preview-bar">
        <div class="preview-logo-icon" id="previewIcon">K</div>
        <span style="font-size:14px;font-weight:700">Keek</span>
        <div style="flex:1"></div>
        <div class="preview-btn" id="previewBtn">Salvar</div>
      </div>
    </div>
  </div>

  <!-- Logo -->
  <div class="ap-card">
    <div class="ap-card-title"><i class="ph ph-image" style="color:var(--accent)"></i> Logo da plataforma</div>
    <div class="ap-card-desc">Exibida na sidebar e na tela de login. Tamanho recomendado: 200×60 px. Formatos: PNG, JPG, WebP ou GIF. Máximo 2 MB.</div>

    <?php if ($logoUrl): ?>
    <div class="logo-preview-wrap" id="logoCurrentWrap">
      <img src="<?= BASE_PATH . htmlspecialchars($logoUrl) ?>?t=<?= time() ?>" alt="Logo atual" class="logo-preview-img">
      <div>
        <button type="button" class="btn-remove-logo"
                onclick="if(confirm('Remover a logo atual?')) document.getElementById('formRemoverLogo').submit()">
          <i class="ph ph-trash"></i> Remover logo
        </button>
      </div>
    </div>
    <?php endif; ?>

    <div id="logoPreviewWrap" style="display:none;margin-bottom:18px;text-align:center">
      <img src="" alt="Pré-visualização" class="logo-preview-img" id="logoPreviewImg">
    </div>

    <div class="logo-upload-area" id="uploadArea" onclick="document.getElementById('logoFile').click()">
      <i class="ph ph-upload-simple" style="font-size:28px;color:var(--muted);margin-bottom:8px"></i>
      <div id="uploadLabel" style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:4px">Clique para escolher uma imagem</div>
      <div style="font-size:12px;color:var(--muted)">PNG, JPG, WebP ou GIF — máx. 2 MB</div>
      <input type="file" name="logo" id="logoFile" accept="image/png,image/jpeg,image/webp,image/gif"
             style="display:none" onchange="previewLogo(this)">
    </div>
  </div>

</div><!-- .ap-grid -->

<div style="margin-top:24px;max-width:820px;display:flex;justify-content:flex-end">
  <button type="submit" class="btn-primary" style="padding:11px 28px;font-size:14px">
    <i class="ph ph-floppy-disk" style="margin-right:6px"></i> Salvar aparência
  </button>
</div>
</form>

<script>
function syncColor(val) {
  const ok = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(val);
  if (ok) {
    document.getElementById('colorPicker').value = val;
    document.getElementById('corInput').value    = val;
    document.getElementById('hexInput').value    = val;
    document.getElementById('previewIcon').style.background = val;
    document.getElementById('previewBtn').style.background  = val;
  }
}

document.getElementById('colorPicker').addEventListener('input', function() {
  document.getElementById('hexInput').value = this.value;
  syncColor(this.value);
});

function applyColor(hex) {
  document.getElementById('hexInput').value = hex;
  syncColor(hex);
}

syncColor(document.getElementById('corInput').value);

function previewLogo(input) {
  if (!input.files || !input.files[0]) return;
  const reader = new FileReader();
  reader.onload = function(e) {
    const img  = document.getElementById('logoPreviewImg');
    const wrap = document.getElementById('logoPreviewWrap');
    img.src = e.target.result;
    wrap.style.display = 'block';
  };
  reader.readAsDataURL(input.files[0]);
  document.getElementById('uploadLabel').textContent = input.files[0].name;
  document.getElementById('uploadArea').style.borderColor = 'var(--accent)';
}

const area = document.getElementById('uploadArea');
area.addEventListener('dragover', e => { e.preventDefault(); area.classList.add('drag'); });
area.addEventListener('dragleave', ()  => area.classList.remove('drag'));
area.addEventListener('drop', e => {
  e.preventDefault();
  area.classList.remove('drag');
  const file = e.dataTransfer.files[0];
  if (file) {
    const dt  = new DataTransfer();
    dt.items.add(file);
    const inp = document.getElementById('logoFile');
    inp.files = dt.files;
    previewLogo(inp);
  }
});
</script>
