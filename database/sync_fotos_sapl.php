<?php
/**
 * sync_fotos_sapl.php
 *
 * Busca fotografia_url no SAPL para parlamentares que ainda estão com NULL,
 * baixa a imagem e salva localmente. Nunca sobrescreve paths /uploads/ já existentes.
 *
 *   php database/sync_fotos_sapl.php                  — todas as fontes SAPL
 *   php database/sync_fotos_sapl.php alpb cmjp         — fontes específicas
 *   php database/sync_fotos_sapl.php alpb --force      — rebaixa mesmo quem já tem local
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$args    = array_slice($argv, 1);
$force   = in_array('--force', $args);
$fontes  = array_values(array_filter($args, fn($a) => $a !== '--force'));

$saplFontes = array_filter(SOURCES, fn($s) => isset($s['url']) && str_contains($s['url'], 'sapl'));
if ($fontes) {
    $saplFontes = array_intersect_key($saplFontes, array_flip($fontes));
}

if (!$saplFontes) { echo "Nenhuma fonte SAPL encontrada.\n"; exit(1); }

$pdo = Database::connect();
$stUpdate = $pdo->prepare(
    "UPDATE parl_parlamentares SET fotografia_url=? WHERE source_key=? AND sapl_id=?"
);

function curlJson(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $body) ? json_decode($body, true) : null;
}

function curlDownloadImg(string $url, string $dest): bool {
    $fp = fopen($dest, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    $size = file_exists($dest) ? filesize($dest) : 0;
    if ($code !== 200 || $size < 2000) { @unlink($dest); return false; }
    return true;
}

foreach ($saplFontes as $source => $cfg) {
    $saplUrl   = rtrim($cfg['url'], '/');
    $uploadDir = ROOT . '/public/uploads/parlamentares/' . $source;
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $where  = $force ? "" : " AND (fotografia_url IS NULL OR fotografia_url = '')";
    $rows   = $pdo->query(
        "SELECT sapl_id, nome_parlamentar FROM parl_parlamentares
         WHERE source_key='$source'$where ORDER BY sapl_id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $total = count($rows);
    echo "┌─ {$source} ({$cfg['label']}) — {$total} a processar\n";

    $ok = $skip = $semFoto = $erro = 0;

    foreach ($rows as $r) {
        $id   = (int)$r['sapl_id'];
        $data = curlJson("{$saplUrl}/api/parlamentares/parlamentar/{$id}/?format=json");
        usleep(120000); // 120ms entre requisições

        if (!$data) { $erro++; continue; }

        $fotoUrl = $data['fotografia'] ?? null;
        if (!$fotoUrl) { $semFoto++; continue; }

        // Garante URL absoluta
        if (!str_starts_with($fotoUrl, 'http')) {
            $fotoUrl = $saplUrl . '/' . ltrim($fotoUrl, '/');
        }

        $ext       = strtolower(pathinfo(parse_url($fotoUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
        $ext       = in_array($ext, ['jpg','jpeg','png','gif','webp']) ? $ext : 'jpg';
        $localPath = $uploadDir . '/' . $id . '.' . $ext;
        $localUrl  = '/uploads/parlamentares/' . $source . '/' . $id . '.' . $ext;

        if (!$force && file_exists($localPath) && filesize($localPath) > 2000) {
            $stUpdate->execute([$localUrl, $source, $id]);
            $skip++;
            continue;
        }

        if (!curlDownloadImg($fotoUrl, $localPath)) {
            echo "  ✗ {$r['nome_parlamentar']}: erro no download\n";
            $erro++;
            continue;
        }

        $stUpdate->execute([$localUrl, $source, $id]);
        echo "  ✓ {$r['nome_parlamentar']}: " . round(filesize($localPath)/1024) . "KB\n";
        $ok++;
    }

    echo "└─ {$source}: {$ok} baixadas, {$skip} já locais, {$semFoto} sem foto no SAPL, {$erro} erros\n\n";
}

echo "[sync_fotos_sapl] Concluído.\n";
