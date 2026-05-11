<?php
/**
 * Sync de detalhes por parlamentar
 *
 * Popula o sapl_cache com os dados de TODAS as abas por parlamentar,
 * usando exatamente as mesmas chaves de cache que o proxy usa.
 * Após rodar este script o app nunca precisa chamar servidores do governo
 * — todo tráfego é servido do banco local (TTL 7 dias).
 *
 * Pré-requisito: rodar sync.php primeiro para popular parl_parlamentares.
 *
 * Uso:
 *   php database/sync_detalhes.php                         — fontes com status 'ok'
 *   php database/sync_detalhes.php camara_federal alpb senado
 *   php database/sync_detalhes.php camara_federal --force  — ignora cache existente
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
define('SAPL_CURL_TIMEOUT', 8); // timeout menor para o sync não travar em endpoints lentos
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';
require APP  . '/Core/SaplApi.php';
require APP  . '/Models/SaplCache.php';

const TTL     = 168;   // 7 dias em horas
const MAX_PAG = 100;   // páginas máximas por endpoint
const DELAY   = 150;   // ms de pausa entre chamadas ao governo
const CURL_TO = 8;     // segundos de timeout por requisição

// ── Bootstrap ────────────────────────────────────────────────────────────────

$pdo    = Database::connect();
$args   = array_slice($argv, 1);
$force  = in_array('--force', $args);
$args   = array_values(array_filter($args, fn($a) => $a !== '--force'));

$todasFontes = array_keys(SOURCES);
if ($args) {
    $fontesAlvo = array_values(array_filter($todasFontes, fn($k) => in_array($k, $args)));
} else {
    $rows = $pdo->query("SELECT source_key FROM fonte_sincs WHERE status='ok' ORDER BY source_key")->fetchAll();
    $fontesAlvo = array_column($rows, 'source_key');
}

if (empty($fontesAlvo)) {
    echo "Nenhuma fonte disponível. Rode sync.php primeiro ou passe as fontes como argumento.\n";
    exit(1);
}

$inicio = microtime(true);
echo "[detalhes] Fontes: " . implode(', ', $fontesAlvo) . ($force ? ' [--force]' : '') . "\n\n";

// ── warmPages ────────────────────────────────────────────────────────────────
//
// Busca e armazena no sapl_cache todas as páginas de um endpoint.
//
// $pathFull = path como o frontend passa para o proxy (com params embutidos).
//   Ex.: '/parlamentares/filiacao/?parlamentar=123'
//   Ex.: '/parlamentares/filiacao/?parlamentar=123&o=-data'
//
// A chave de cache gerada é: $pathFull . '&page=' . $pg
// Isso espelha exatamente o que ApiController::proxy() gera:
//   $cacheKey = $path . ($extra ? '&' . http_build_query(ksorted($extra)) : '')
// onde $path = pathFull e $extra = ['page' => $pg].

function warmPages(string $source, string $pathFull, bool $force = false): int {
    $total = 0;
    $page  = 1;

    do {
        $cacheKey = $pathFull . '&page=' . $page;

        if (!$force && SaplCache::get($source, $cacheKey) !== null) {
            // Cache válido — só decodifica para saber paginação
            $raw = SaplCache::get($source, $cacheKey);
        } else {
            $raw = SaplApi::getRaw($pathFull, $source, ['page' => $page]);
            if ($raw !== '{}' && !str_contains($raw, '__rate_limited')) {
                SaplCache::set($source, $cacheKey, $raw, TTL);
            }
            usleep(DELAY * 1000);
        }

        $data      = json_decode($raw, true) ?: [];
        $results   = $data['results'] ?? [];
        if (empty($results)) break;

        $total    += count($results);
        $totalPags = (int)($data['pagination']['total_pages'] ?? 1);
        $page++;
    } while ($page <= $totalPags);

    return $total;
}

// Variante de página única (sem iterar paginação)
function warmOnePage(string $source, string $pathFull, bool $force = false): void {
    $cacheKey = $pathFull . '&page=1';
    if (!$force && SaplCache::get($source, $cacheKey) !== null) return;
    $raw = SaplApi::getRaw($pathFull, $source, ['page' => 1]);
    if ($raw !== '{}' && !str_contains($raw, '__rate_limited')) {
        SaplCache::set($source, $cacheKey, $raw, TTL);
    }
    usleep(DELAY * 1000);
}

// Resolve autor ID para fontes SAPL genéricas.
// 1) Tenta bulk /base/autor/ (object_id match) — funciona em servidores que retornam lista completa.
// 2) Fallback: busca por nome via /base/autor/?nome=X — necessário para servidores como ALPB
//    que retornam vazio no bulk. Persiste no cache para sync_estruturado reutilizar.
function resolveAutorSapl(PDO $pdo, string $source, int $parlamentarSaplId, string $nome = '', ?string $nomeC = null): ?int {
    // 1. Bulk cache lookup
    $stmt = $pdo->prepare(
        "SELECT data FROM sapl_cache
         WHERE source=? AND cache_key LIKE '/base/autor/&page=%' AND expires_at > NOW()
         ORDER BY cache_key"
    );
    $stmt->execute([$source]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $data = json_decode($raw, true) ?: [];
        foreach ($data['results'] ?? [] as $r) {
            if ((int)($r['object_id'] ?? -1) === $parlamentarSaplId) {
                return (int)($r['id'] ?? 0) ?: null;
            }
        }
    }
    // 2. Fallback name-based — busca na API e persiste no cache
    foreach (array_unique(array_filter([$nome, $nomeC])) as $n) {
        $cacheKey = '/base/autor/&' . http_build_query(['nome' => $n]);
        $cached = SaplCache::get($source, $cacheKey);
        if ($cached === null) {
            $cached = SaplApi::getRaw('/base/autor/', $source, ['nome' => $n]);
            if ($cached && $cached !== '{}' && !str_contains($cached, '__rate_limited')) {
                SaplCache::set($source, $cacheKey, $cached, TTL);
            }
            usleep(DELAY * 1000);
        }
        if ($cached) {
            $data = json_decode($cached, true) ?: [];
            $aid  = (int)($data['results'][0]['id'] ?? 0);
            if ($aid) return $aid;
        }
    }
    return null;
}

// ── Loop principal ────────────────────────────────────────────────────────────

foreach ($fontesAlvo as $source) {
    $isSapl = !in_array($source, ['camara_federal', 'senado']);
    $fInicio = microtime(true);

    echo "┌─ {$source} " . str_repeat('─', max(0, 50 - strlen($source))) . "\n";

    // ── 1. Dados globais da fonte ─────────────────────────────────────────────
    echo "│  [global] frentes, frentecargos, tipomateria";
    warmPages($source, '/parlamentares/frente/', $force);
    warmPages($source, '/parlamentares/frentecargo/', $force);
    warmPages($source, '/materia/tipomateria/', $force);
    if ($isSapl) {
        warmPages($source, '/norma/tiponormajuridica/', $force);
        echo ", tiponorma";
        // Busca todos os autores (parlamentares) de uma vez pelo object_id
        // Evita a busca frágil por nome que falha para muitos parlamentares
        warmPages($source, '/base/autor/', $force);
        echo ", autores";
    }
    echo "\n";

    // ── 2. Por parlamentar ────────────────────────────────────────────────────
    $rows = $pdo->prepare(
        "SELECT sapl_id, nome_parlamentar, nome_completo
         FROM parl_parlamentares WHERE source_key = ? ORDER BY sapl_id"
    );
    $rows->execute([$source]);
    $parlamentares = $rows->fetchAll();
    $total  = count($parlamentares);
    $done   = 0;
    $erros  = 0;
    $hits   = 0; // endpoints já em cache

    echo "│  {$total} parlamentares\n";

    foreach ($parlamentares as $parl) {
        $done++;
        $id    = (int)$parl['sapl_id'];
        $nome  = $parl['nome_parlamentar'] ?: $parl['nome_completo'];
        $nomeC = $parl['nome_completo'];

        if ($done === 1 || $done % 50 === 0 || $done === $total) {
            $pct = round($done / $total * 100);
            echo "│  [{$done}/{$total} {$pct}%] {$nome}\n";
            flush();
        }

        try {
            // Perfil / Início
            warmOnePage($source, "/parlamentares/perfil/?parlamentar={$id}", $force);

            // Mandatos
            warmPages($source, "/parlamentares/mandato/?parlamentar={$id}", $force);

            // Filiações — duas variantes (aba + party slot do card)
            warmPages($source, "/parlamentares/filiacao/?parlamentar={$id}", $force);
            warmOnePage($source, "/parlamentares/filiacao/?parlamentar={$id}&o=-data", $force);

            // Comissões
            warmPages($source, "/comissoes/participacao/?parlamentar={$id}", $force);

            // Relatorias
            warmPages($source, "/materia/relatoria/?parlamentar={$id}", $force);

            // Frentes
            warmPages($source, "/parlamentares/frenteparlamentar/?parlamentar={$id}", $force);

            // Matérias e Normas precisam do autor ID
            $autorId = $isSapl
                ? resolveAutorSapl($pdo, $source, $id, $nome, $nomeC)
                : $id;

            if ($autorId) {
                warmPages($source, "/materia/autoria/?autor={$autorId}&o=-id", $force);
                if ($isSapl) {
                    warmPages($source, "/norma/autorianorma/?autor={$autorId}", $force);
                }
            }

        } catch (Throwable $e) {
            $erros++;
            echo "│  ERRO [{$id}] {$e->getMessage()}\n";
        }
    }

    $dur = round(microtime(true) - $fInicio);
    echo "└─ {$source}: {$total} parlamentares, {$erros} erros — {$dur}s\n\n";

    // Atualiza detalhes_em em fonte_sincs
    $pdo->prepare("UPDATE fonte_sincs SET detalhes_em=NOW() WHERE source_key=?")
        ->execute([$source]);
}

$total = round(microtime(true) - $inicio);
echo "[detalhes] Concluído em {$total}s.\n";
