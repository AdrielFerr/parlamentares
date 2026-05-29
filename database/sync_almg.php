<?php
/**
 * sync_almg.php
 *
 * Importa deputados da ALMG via CSV público (sem paginação de API).
 * Fotos baixadas de https://www.almg.gov.br/export/sites/portal/a-assembleia/deputados/fotos/{id}.jpg
 *
 * Uso:
 *   php database/sync_almg.php              — importa e baixa fotos
 *   php database/sync_almg.php --force      — rebaixa fotos já existentes
 *   php database/sync_almg.php --skip-fotos — apenas dados, sem baixar fotos
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$args       = array_slice($argv, 1);
$force      = in_array('--force', $args);
$skipFotos  = in_array('--skip-fotos', $args);

$pdo = Database::connect();

const CSV_URL   = 'https://dadosabertos.almg.gov.br/arquivo/deputados-legislatura/download';
const FOTO_BASE = 'https://www.almg.gov.br/export/sites/portal/a-assembleia/deputados/fotos/';
const SOURCE    = 'almg';
const UF        = 'MG';

$uploadDir = ROOT . '/public/uploads/parlamentares/' . SOURCE;
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$inicio = microtime(true);
echo "[almg] Baixando CSV...\n";

// ── 1. Baixa o CSV ───────────────────────────────────────────────────────────

$ch = curl_init(CSV_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
    CURLOPT_SSL_VERIFYPEER => false,
]);
$csv  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$csv || $code !== 200) {
    echo "[almg] ERRO: CSV não acessível (HTTP {$code})\n";
    exit(1);
}

$linhas = array_filter(explode("\n", trim($csv)));
array_shift($linhas); // remove cabeçalho

echo "[almg] " . count($linhas) . " deputados encontrados no CSV\n\n";

// ── 2. Prepara statements ─────────────────────────────────────────────────────

$stUpsert = $pdo->prepare("
    INSERT INTO parl_parlamentares
        (source_key, sapl_id, nome_completo, nome_parlamentar, partido_sigla, uf,
         fotografia_url, email, ativo, sincronizado_em, titular)
    VALUES (?,?,?,?,?,?, ?,?,1,NOW(),1)
    ON DUPLICATE KEY UPDATE
        nome_completo    = VALUES(nome_completo),
        nome_parlamentar = VALUES(nome_parlamentar),
        partido_sigla    = VALUES(partido_sigla),
        ativo            = 1,
        sincronizado_em  = NOW()
");

$stFoto = $pdo->prepare(
    "UPDATE parl_parlamentares SET fotografia_url=? WHERE source_key=? AND sapl_id=?"
);

// ── 3. Processa cada deputado ─────────────────────────────────────────────────

$ok = $skip = $semFoto = 0;

foreach ($linhas as $linha) {
    $linha = trim($linha);
    if (!$linha) continue;

    // CSV: "Codigo","Nome","Partido","PaginaAlmg"
    $cols = str_getcsv($linha, ',', '"');
    if (count($cols) < 3) continue;

    $id      = (int)$cols[0];
    $nome    = trim($cols[1]);
    $partido = trim($cols[2]);

    if (!$id || !$nome) continue;

    // Insere / atualiza
    $localUrl = '/uploads/parlamentares/' . SOURCE . '/' . $id . '.jpg';
    $stUpsert->execute([SOURCE, $id, $nome, $nome, $partido, UF, $localUrl, null]);

    // ── Foto ──
    if ($skipFotos) {
        $ok++;
        continue;
    }

    $localPath = $uploadDir . '/' . $id . '.jpg';

    if (!$force && file_exists($localPath)) {
        $stFoto->execute([$localUrl, SOURCE, $id]);
        $skip++;
        continue;
    }

    $fotoUrl = FOTO_BASE . $id . '.jpg';
    $fc = curl_init($fotoUrl);
    curl_setopt_array($fc, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body  = curl_exec($fc);
    $fcode = curl_getinfo($fc, CURLINFO_HTTP_CODE);
    curl_close($fc);

    if ($body && $fcode === 200 && strlen($body) > 5000) {
        file_put_contents($localPath, $body);
        $stFoto->execute([$localUrl, SOURCE, $id]);
        $ok++;
        echo "  ✓ {$nome} [{$id}]\n";
    } else {
        $semFoto++;
        echo "  - {$nome} [{$id}] sem foto (HTTP {$fcode})\n";
    }

    usleep(100000); // 100ms entre downloads
}

// ── 4. Atualiza fonte_sincs ───────────────────────────────────────────────────

$total = $ok + $skip + $semFoto;
$pdo->prepare("
    INSERT INTO fonte_sincs (source_key, status, iniciado_em, concluido_em, total_parl)
    VALUES (?, 'ok', NOW(), NOW(), ?)
    ON DUPLICATE KEY UPDATE status='ok', concluido_em=NOW(), total_parl=?
")->execute([SOURCE, $total, $total]);

$dur = round(microtime(true) - $inicio);
echo "\n[almg] Concluído em {$dur}s — {$ok} fotos baixadas, {$skip} já existiam, {$semFoto} sem foto\n";
echo "[almg] Total: {$total} deputados importados.\n";
