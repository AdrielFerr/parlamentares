<?php
/**
 * sync_fotos.php
 *
 * Baixa as fotos dos parlamentares dos servidores do governo e armazena
 * localmente em public/uploads/parlamentares/{source}/{sapl_id}.{ext}
 * Atualiza fotografia_url na tabela parl_parlamentares para o caminho local.
 *
 * Uso:
 *   php database/sync_fotos.php                        — todas as fontes
 *   php database/sync_fotos.php alpb camara_federal    — fontes específicas
 *   php database/sync_fotos.php alpb --force           — rebaixa mesmo se já existe
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$pdo   = Database::connect();
$args  = array_slice($argv, 1);
$force = in_array('--force', $args);
$args  = array_values(array_filter($args, fn($a) => $a !== '--force'));

$todasFontes = array_keys(SOURCES);
$fontesAlvo  = $args
    ? array_values(array_filter($todasFontes, fn($k) => in_array($k, $args)))
    : $todasFontes;

$uploadBase = ROOT . '/public/uploads/parlamentares';
if (!is_dir($uploadBase)) mkdir($uploadBase, 0755, true);

$inicio = microtime(true);
echo "[fotos] Fontes: " . implode(', ', $fontesAlvo) . ($force ? ' [--force]' : '') . "\n\n";

$stUpdate = $pdo->prepare(
    "UPDATE parl_parlamentares SET fotografia_url=? WHERE source_key=? AND sapl_id=?"
);

foreach ($fontesAlvo as $source) {
    $dir = $uploadBase . '/' . $source;
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $st = $pdo->prepare(
        "SELECT sapl_id, nome_parlamentar, fotografia_url
         FROM parl_parlamentares
         WHERE source_key = ?
           AND fotografia_url IS NOT NULL AND fotografia_url != ''
         ORDER BY sapl_id"
    );
    $st->execute([$source]);
    $rows  = $st->fetchAll(PDO::FETCH_ASSOC);
    $total = count($rows);
    $ok    = 0;
    $skip  = 0;
    $erro  = 0;

    echo "┌─ {$source} — {$total} parlamentares com foto\n";

    foreach ($rows as $r) {
        $id  = (int)$r['sapl_id'];
        $url = $r['fotografia_url'];

        // Se já é um caminho local, pula (a não ser que --force)
        if (str_starts_with($url, '/uploads/') || str_starts_with($url, 'uploads/')) {
            if (!$force) { $skip++; continue; }
        }

        // Detecta extensão a partir da URL
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) $ext = 'jpg';

        $localPath = $dir . '/' . $id . '.' . $ext;
        $localUrl  = '/uploads/parlamentares/' . $source . '/' . $id . '.' . $ext;

        if (!$force && file_exists($localPath)) {
            $stUpdate->execute([$localUrl, $source, $id]);
            $skip++;
            continue;
        }

        // Baixa a imagem
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body && $code === 200) {
            // Ajusta extensão pelo Content-Type se necessário
            if (str_contains($mime, 'png'))  $ext = 'png';
            elseif (str_contains($mime, 'gif'))  $ext = 'gif';
            elseif (str_contains($mime, 'webp')) $ext = 'webp';
            else $ext = 'jpg';

            $localPath = $dir . '/' . $id . '.' . $ext;
            $localUrl  = '/uploads/parlamentares/' . $source . '/' . $id . '.' . $ext;

            file_put_contents($localPath, $body);
            $stUpdate->execute([$localUrl, $source, $id]);
            $ok++;
        } else {
            // Download falhou — mantém URL original para o browser tentar carregar direto
            $erro++;
        }

        usleep(80000); // 80ms entre downloads
    }

    $dur = round(microtime(true) - $inicio);
    echo "└─ {$source}: {$ok} baixadas, {$skip} já locais, {$erro} sem foto — {$dur}s\n\n";
}

echo "[fotos] Concluído em " . round(microtime(true) - $inicio) . "s.\n";
