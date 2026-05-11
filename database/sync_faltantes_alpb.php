<?php
/**
 * Sync direcionado para parlamentares ALPB com autor_id errado ou faltando matérias.
 * Detecta automaticamente conflitos de object_id (ex: parlamentar e frente com mesmo ID)
 * e busca todas as páginas de autoria com o autor_id correto (content_type=2).
 *
 * Uso: php database/sync_faltantes_alpb.php
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

$pdo = Database::connect();

function warmPages(string $source, string $pathFull): int {
    $total = 0; $page = 1;
    do {
        $cacheKey = $pathFull . '&page=' . $page;
        $raw = SaplApi::getRaw($pathFull, $source, ['page' => $page]);
        usleep(DELAY * 1000);
        if ($raw && $raw !== '{}' && !str_contains($raw, '__rate_limited')) {
            SaplCache::set($source, $cacheKey, $raw, TTL);
        }
        $data    = json_decode($raw, true) ?: [];
        $results = $data['results'] ?? [];
        if (empty($results)) break;
        $total += count($results);
        $totalPags = (int)($data['pagination']['total_pages'] ?? 1);
        $page++;
    } while ($page <= $totalPags);
    return $total;
}

// Detecta automaticamente parlamentares com object_id conflitante
// (mesmo object_id = sapl_id aparece para content_type=2 parlamentar E outro tipo)
$stmt = $pdo->query(
    "SELECT data FROM sapl_cache WHERE source='alpb' AND cache_key LIKE '/base/autor/&page=%' AND expires_at > NOW()"
);
$byObjectId = []; // object_id => [content_type => [id, nome]]
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
    $data = json_decode($raw, true) ?: [];
    foreach ($data['results'] ?? [] as $r) {
        $oid  = (int)($r['object_id'] ?? -1);
        $ct   = (int)($r['content_type'] ?? 0);
        $aid  = (int)($r['id'] ?? 0);
        $nome = $r['nome'] ?? '';
        if ($oid > 0 && $ct > 0 && $aid > 0) {
            $byObjectId[$oid][$ct] = ['id' => $aid, 'nome' => $nome];
        }
    }
}

// Parlamentares conhecidos com conflito (mapa manual de fallback)
$mapa = [];

$parls = $pdo->query(
    "SELECT sapl_id, nome_parlamentar FROM parl_parlamentares WHERE source_key='alpb' ORDER BY sapl_id"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($parls as $p) {
    $sid = (int)$p['sapl_id'];
    if (!isset($byObjectId[$sid])) continue;
    $cts = $byObjectId[$sid];
    // Conflito: mesmo object_id em múltiplos content_types, um deles é parlamentar (ct=2)
    if (count($cts) > 1 && isset($cts[2])) {
        $mapa[$sid] = ['nome' => $cts[2]['nome'], 'autor_id' => $cts[2]['id']];
    }
}

echo "[faltantes] " . count($mapa) . " parlamentares com autor_id conflitante detectados\n\n";

foreach ($mapa as $saplId => $info) {
    $autorId = $info['autor_id'];
    echo "── sapl_id={$saplId} {$info['nome']} (autor_id={$autorId})\n";

    $mat = warmPages('alpb', "/materia/autoria/?autor={$autorId}&o=-id");
    $nor = warmPages('alpb', "/norma/autorianorma/?autor={$autorId}");
    echo "   materias_cached={$mat} normas_cached={$nor}\n\n";
}

echo "Concluído. Rode agora: php database/sync_estruturado.php alpb\n";
