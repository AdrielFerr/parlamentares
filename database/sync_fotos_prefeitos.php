<?php
/**
 * sync_fotos_prefeitos.php
 *
 * Baixa fotos dos prefeitos do CDN do TSE e armazena localmente.
 * Lê tse_sq direto do banco — não precisa re-baixar CSV de candidatura.
 *
 *   php database/sync_fotos_prefeitos.php           — todos os estados
 *   php database/sync_fotos_prefeitos.php RJ PB PE  — estados específicos
 *   php database/sync_fotos_prefeitos.php --force   — rebaixa mesmo se já existe
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$args      = array_slice($argv, 1);
$force     = in_array('--force', $args);
$ufsArg    = array_values(array_filter($args, fn($a) => strlen($a) === 2 && ctype_alpha($a)));
$pdo       = Database::connect();
$uploadDir = ROOT . '/public/uploads/parlamentares/prefeitos';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// TSE CDN não suporta Range — sempre retorna 200 com conteúdo completo.
// Por isso não há resume: cada tentativa apaga o arquivo e recomeça do zero.
function curlDownload(string $url, string $dest, int $timeoutSecs = 7200, int $maxTries = 10): bool {
    for ($try = 1; $try <= $maxTries; $try++) {
        if (file_exists($dest)) @unlink($dest);
        $fp = fopen($dest, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE            => $fp,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_TIMEOUT         => $timeoutSecs,
            CURLOPT_SSL_VERIFYPEER  => false,
            CURLOPT_USERAGENT       => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
        ]);
        curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $dlBytes = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        $dlSecs  = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);
        fclose($fp);

        $fileSize = file_exists($dest) ? filesize($dest) : 0;
        $mb   = round($fileSize / 1048576, 1);
        $kbps = $dlSecs > 0 ? round($dlBytes / 1024 / $dlSecs) : 0;

        if ($code !== 200 || $fileSize < 10000) {
            echo "\n    tentativa {$try}/{$maxTries}: HTTP {$code}, {$mb}MB\n";
            continue;
        }

        // Valida integridade do ZIP antes de aceitar
        $za = new ZipArchive();
        if ($za->open($dest) === true) {
            $za->close();
            return true;
        }

        echo "\n    tentativa {$try}/{$maxTries}: {$mb}MB (~{$kbps}KB/s) ZIP incompleto, aguardando 60s...\n";
        flush();
        if ($try < $maxTries) sleep(60);
    }
    @unlink($dest);
    return false;
}

// Busca prefeitos com tse_sq no banco
$sql    = "SELECT sapl_id, uf, nome_parlamentar, tse_sq FROM parl_parlamentares
           WHERE source_key='prefeitos' AND tse_sq IS NOT NULL AND tse_sq != ''";
$params = [];
if ($ufsArg) {
    $in     = implode(',', array_fill(0, count($ufsArg), '?'));
    $sql   .= " AND uf IN ($in)";
    $params = $ufsArg;
}
$rows = $pdo->prepare($sql);
$rows->execute($params);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) { echo "Nenhum prefeito com tse_sq encontrado.\n"; exit(1); }

// Agrupa por UF
$byUf = [];
foreach ($rows as $r) { $byUf[$r['uf']][] = $r; }

echo "[fotos_prefeitos] " . count($rows) . " prefeitos em " . count($byUf) . " estados\n\n";

$stFoto = $pdo->prepare(
    "UPDATE parl_parlamentares SET fotografia_url=? WHERE source_key='prefeitos' AND sapl_id=?"
);

$ok = $skip = $erro = 0;

foreach ($byUf as $uf => $prefeitos) {
    $zipUrl = "https://cdn.tse.jus.br/estatistica/sead/eleicoes/eleicoes2024/fotos/foto_cand2024_{$uf}_div.zip";
    $zipTmp = sys_get_temp_dir() . "/tse_fotos2024_{$uf}.zip";

    // Verifica se ZIP em cache é válido antes de reusar
    $zipCacheOk = false;
    if (file_exists($zipTmp) && filesize($zipTmp) > 10000) {
        $zaCheck = new ZipArchive();
        if ($zaCheck->open($zipTmp) === true) { $zaCheck->close(); $zipCacheOk = true; }
    }

    if (!$zipCacheOk) {
        echo "  Baixando fotos {$uf}... ";
        flush();
        if (!curlDownload($zipUrl, $zipTmp, 7200, 10)) {
            echo "ERRO (URL: $zipUrl)\n";
            $erro += count($prefeitos);
            continue;
        }
        echo round(filesize($zipTmp) / 1024 / 1024, 1) . "MB\n";
    } else {
        echo "  {$uf}: usando ZIP em cache (" . round(filesize($zipTmp) / 1024 / 1024, 1) . "MB)\n";
    }

    $za = new ZipArchive();
    if ($za->open($zipTmp) !== true) {
        echo "  {$uf}: ERRO ao abrir ZIP\n";
        $erro += count($prefeitos);
        continue;
    }

    foreach ($prefeitos as $p) {
        $dest = $uploadDir . '/' . $p['sapl_id'] . '.jpg';

        if (!$force && file_exists($dest) && filesize($dest) > 5000) {
            $stFoto->execute(['/uploads/parlamentares/prefeitos/' . $p['sapl_id'] . '.jpg', $p['sapl_id']]);
            $skip++;
            continue;
        }

        $sq      = $p['tse_sq'];
        $content = $za->getFromName("F{$uf}{$sq}_div.jpg")
                ?: $za->getFromName("F{$uf}{$sq}_div.jpeg")
                ?: $za->getFromName("{$sq}.jpg");

        if ($content === false || strlen($content) < 5000) {
            echo "  - {$p['nome_parlamentar']} ({$uf}): foto não encontrada (SQ={$sq})\n";
            $erro++;
            continue;
        }

        file_put_contents($dest, $content);
        $url = '/uploads/parlamentares/prefeitos/' . $p['sapl_id'] . '.jpg';
        $stFoto->execute([$url, $p['sapl_id']]);
        echo "  ✓ {$p['nome_parlamentar']} ({$uf}): " . round(strlen($content) / 1024) . "KB\n";
        $ok++;
    }

    $za->close();
}

echo "\n[fotos_prefeitos] OK={$ok}  skip={$skip}  erro={$erro}\n";
