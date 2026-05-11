<?php
/**
 * Busca as páginas de matérias cortadas pelo limite anterior de MAX_PAG=100.
 * Para cada autor cujo total_pages > páginas já em cache, busca as páginas faltantes.
 *
 * Uso: php database/sync_materias_paginas.php [source]
 *   source padrão: alpb
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Acesso negado.'); }

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';
require APP  . '/Core/SaplApi.php';
require APP  . '/Models/SaplCache.php';

const TTL   = 168;
const DELAY = 150;

$pdo    = Database::connect();
$source = $argv[1] ?? 'alpb';

// Lê todas as entradas de autoria paginadas do cache
$rows = $pdo->query(
    "SELECT cache_key,
            JSON_UNQUOTE(JSON_EXTRACT(data, '$.pagination.total_pages')) AS total_pages
     FROM sapl_cache
     WHERE source = '$source'
       AND cache_key LIKE '/materia/autoria/%&page=%'
       AND expires_at > NOW()"
)->fetchAll(PDO::FETCH_ASSOC);

// Agrupa por prefixo (tudo antes do &page=N)
$autores = [];
foreach ($rows as $r) {
    if (!preg_match('#^(.+)&page=(\d+)$#', $r['cache_key'], $m)) continue;
    $prefix = $m[1];
    $pag    = (int)$m[2];
    $tot    = (int)($r['total_pages'] ?? 1);

    if (!isset($autores[$prefix])) {
        $autores[$prefix] = ['max_cached' => 0, 'total_pages' => $tot];
    }
    if ($pag > $autores[$prefix]['max_cached'])   $autores[$prefix]['max_cached']  = $pag;
    if ($tot > $autores[$prefix]['total_pages'])   $autores[$prefix]['total_pages'] = $tot;
}

$pendentes = array_filter($autores, fn($v) => $v['max_cached'] < $v['total_pages']);
arsort($pendentes); // ordena pelos que têm mais páginas faltando (não importa, mas ajuda no diagnóstico)
$total = count($pendentes);

$totalPagFaltando = array_sum(array_map(fn($v) => $v['total_pages'] - $v['max_cached'], $pendentes));
$estimMin = round($totalPagFaltando * DELAY / 1000 / 60, 1);

echo "[paginas] Fonte: {$source}\n";
echo "[paginas] {$total} autores com páginas faltando ({$totalPagFaltando} páginas, ~{$estimMin} min)\n\n";

$done = 0;
foreach ($pendentes as $prefix => $info) {
    $done++;
    $from    = $info['max_cached'] + 1;
    $to      = $info['total_pages'];
    $fetched = 0;

    echo "[{$done}/{$total}] {$prefix} — págs {$from}-{$to}\n";
    flush();

    for ($page = $from; $page <= $to; $page++) {
        $cacheKey = $prefix . '&page=' . $page;
        $raw      = SaplApi::getRaw($prefix, $source, ['page' => $page]);
        usleep(DELAY * 1000);

        if (!$raw || $raw === '{}' || str_contains($raw, '__rate_limited')) {
            echo "  pág {$page}: falha/rate-limit, parando\n";
            break;
        }
        $data = json_decode($raw, true) ?: [];
        if (empty($data['results'])) break;

        SaplCache::set($source, $cacheKey, $raw, TTL);
        $fetched += count($data['results']);
    }

    echo "  → {$fetched} matérias em cache\n\n";
}

echo "Concluído. Rode agora:\n  php database/sync_estruturado.php {$source}\n";
