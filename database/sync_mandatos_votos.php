<?php
/**
 * Lê votos_recebidos e coligacao do sapl_cache e atualiza parl_mandatos.
 * Não faz chamadas de API — usa apenas o cache existente.
 *
 * Uso: php database/sync_mandatos_votos.php [source]
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Acesso negado.'); }

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require ROOT . '/app/Core/Database.php';

$pdo    = Database::connect();
$source = $argv[1] ?? 'alpb';

// Prepara update
$stUpdate = $pdo->prepare("
    UPDATE parl_mandatos
    SET votos_recebidos = ?,
        coligacao       = ?
    WHERE source_key = ? AND parlamentar_id = ? AND legislatura_id = ?
");

// Lê todas as páginas de mandato do cache
$rows = $pdo->query(
    "SELECT data FROM sapl_cache
     WHERE source = '$source'
       AND cache_key LIKE '/parlamentares/mandato/%'
       AND expires_at > NOW()"
)->fetchAll(PDO::FETCH_COLUMN);

$updated = 0;
$skipped = 0;

foreach ($rows as $raw) {
    $data = json_decode($raw, true) ?: [];
    foreach ($data['results'] ?? [] as $m) {
        $parlId = (int)($m['parlamentar'] ?? 0);
        $legId  = (int)($m['legislatura'] ?? 0);
        if (!$parlId || !$legId) { $skipped++; continue; }

        $votos = isset($m['votos_recebidos']) && $m['votos_recebidos'] !== '' ? (string)$m['votos_recebidos'] : null;
        $colig = isset($m['coligacao'])       && $m['coligacao']       !== '' ? (string)$m['coligacao']       : null;

        $stUpdate->execute([$votos, $colig, $source, $parlId, $legId]);
        $updated += $stUpdate->rowCount();
    }
}

echo "[mandatos] Fonte: {$source} — {$updated} mandatos atualizados, {$skipped} ignorados\n";
