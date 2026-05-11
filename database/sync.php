<?php
/**
 * Sync semanal de fontes legislativas
 *
 * Busca parlamentares, legislaturas, partidos e mandatos de todas as fontes
 * configuradas e armazena nas tabelas parl_* para evitar requisições repetidas
 * aos servidores do governo. Também aquece o sapl_cache com TTL de 7 dias.
 *
 * Uso:
 *   php database/sync.php              — sincroniza todas as fontes
 *   php database/sync.php cmjp senado  — sincroniza apenas as fontes indicadas
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';
require APP  . '/Core/SaplApi.php';
require APP  . '/Models/SaplCache.php';

// ── Configuração ────────────────────────────────────────────────────────────

const TTL_DIAS = 168; // 7 dias em horas
const MAX_PAGINAS = 100;
const DELAY_PAGINA_MS = 200;  // ms entre páginas da mesma fonte
const DELAY_FONTE_MS  = 600;  // ms entre fontes

// ── Bootstrap ───────────────────────────────────────────────────────────────

$pdo = Database::connect();

$fontesFiltro = array_slice($argv, 1);
$todasFontes  = array_keys(SOURCES);
$fontesAlvo   = $fontesFiltro
    ? array_filter($todasFontes, fn($k) => in_array($k, $fontesFiltro))
    : $todasFontes;

if (empty($fontesAlvo)) {
    $disponiveis = implode(', ', $todasFontes);
    echo "Fontes inválidas. Disponíveis: {$disponiveis}\n";
    exit(1);
}

$inicio = microtime(true);
echo "[sync] Iniciando sync de " . count($fontesAlvo) . " fonte(s)...\n";

// ── Funções utilitárias ──────────────────────────────────────────────────────

function fetchPaginas(string $path, string $source, array $extra = []): array {
    $tudo  = [];
    $pagina = 1;
    do {
        $params   = array_merge($extra, ['page' => $pagina]);
        $raw      = SaplApi::getRaw($path, $source, $params);

        // Aquece o sapl_cache enquanto busca (TTL 7 dias)
        $cacheKey = $path . '?' . http_build_query($params);
        SaplCache::set($source, $cacheKey, $raw, TTL_DIAS);

        $data     = json_decode($raw, true) ?: [];
        $results  = $data['results'] ?? [];
        if (empty($results)) break;

        $tudo       = array_merge($tudo, $results);
        $totalPags  = (int)($data['pagination']['total_pages'] ?? 1);
        $pagina++;
        if ($pagina <= $totalPags) usleep(DELAY_PAGINA_MS * 1000);
    } while ($pagina <= $totalPags && $pagina <= MAX_PAGINAS);

    return $tudo;
}

function upsertParlamentar(PDO $pdo, string $source, array $p): void {
    $pdo->prepare("
        INSERT INTO parl_parlamentares
            (source_key, sapl_id, nome_completo, nome_parlamentar,
             partido_sigla, uf, fotografia_url, email, ativo, titular)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            nome_completo    = VALUES(nome_completo),
            nome_parlamentar = VALUES(nome_parlamentar),
            partido_sigla    = VALUES(partido_sigla),
            uf               = VALUES(uf),
            fotografia_url   = VALUES(fotografia_url),
            email            = VALUES(email),
            ativo            = VALUES(ativo),
            titular          = VALUES(titular),
            sincronizado_em  = NOW()
    ")->execute([
        $source,
        (int)($p['id'] ?? 0),
        $p['nome_completo']    ?? '',
        $p['nome_parlamentar'] ?? '',
        $p['partido']['sigla'] ?? '',
        $p['uf']               ?? '',
        ($p['fotografia'] ?? '') ?: null,
        ($p['email']      ?? '') ?: null,
        isset($p['ativo'])   ? (int)$p['ativo']   : 1,
        isset($p['titular']) ? (int)$p['titular'] : 1,
    ]);
}

function upsertLegislatura(PDO $pdo, string $source, array $l): int {
    $sapl_id = (int)($l['id'] ?? $l['numero'] ?? 0);
    if (!$sapl_id) return 0;
    $pdo->prepare("
        INSERT INTO parl_legislaturas (source_key, sapl_id, numero, data_inicio, data_fim)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            numero      = VALUES(numero),
            data_inicio = VALUES(data_inicio),
            data_fim    = VALUES(data_fim),
            sincronizado_em = NOW()
    ")->execute([
        $source,
        $sapl_id,
        (int)($l['numero'] ?? $l['id'] ?? 0),
        ($l['data_inicio'] ?? '') ?: null,
        ($l['data_fim']    ?? '') ?: null,
    ]);
    return $sapl_id;
}

function upsertPartido(PDO $pdo, string $source, array $p): void {
    $sapl_id = (string)($p['id'] ?? $p['sigla'] ?? '');
    if (!$sapl_id) return;
    $pdo->prepare("
        INSERT INTO parl_partidos (source_key, sapl_id, sigla, nome)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            sigla = VALUES(sigla),
            nome  = VALUES(nome),
            sincronizado_em = NOW()
    ")->execute([
        $source,
        $sapl_id,
        $p['sigla'] ?? '',
        $p['nome']  ?? $p['sigla'] ?? '',
    ]);
}

function upsertMandato(PDO $pdo, string $source, array $m): void {
    $parlId = (int)($m['parlamentar'] ?? 0);
    $legId  = (int)($m['legislatura'] ?? 0);
    if (!$parlId || !$legId) return;
    $votos    = isset($m['votos_recebidos']) && $m['votos_recebidos'] !== '' ? (string)$m['votos_recebidos'] : null;
    $colig    = isset($m['coligacao'])      && $m['coligacao']      !== '' ? (string)$m['coligacao']      : null;
    $pdo->prepare("
        INSERT INTO parl_mandatos (source_key, parlamentar_id, legislatura_id, titular, votos_recebidos, coligacao)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            titular         = VALUES(titular),
            votos_recebidos = COALESCE(VALUES(votos_recebidos), votos_recebidos),
            coligacao       = COALESCE(VALUES(coligacao), coligacao),
            sincronizado_em = NOW()
    ")->execute([
        $source,
        $parlId,
        $legId,
        isset($m['titular']) ? (int)$m['titular'] : 1,
        $votos,
        $colig,
    ]);
}

// ── Loop principal ───────────────────────────────────────────────────────────

$totalFontes = count($fontesAlvo);
$i = 0;

foreach ($fontesAlvo as $source) {
    $i++;
    echo "\n[sync] ── {$i}/{$totalFontes}: {$source} ──────────────────────────────\n";

    $pdo->prepare("
        INSERT INTO fonte_sincs (source_key, status, iniciado_em, detalhes)
        VALUES (?, 'executando', NOW(), NULL)
        ON DUPLICATE KEY UPDATE
            status='executando', iniciado_em=NOW(), concluido_em=NULL, detalhes=NULL
    ")->execute([$source]);

    try {
        // 1. Parlamentares ────────────────────────────────────────────────────
        echo "[sync]   [1/4] Parlamentares... ";
        $parlamentares = fetchPaginas('/parlamentares/parlamentar', $source);
        echo count($parlamentares) . " encontrados\n";
        foreach ($parlamentares as $p) {
            upsertParlamentar($pdo, $source, $p);
        }

        // 2. Legislaturas ─────────────────────────────────────────────────────
        echo "[sync]   [2/4] Legislaturas... ";
        $legislaturas  = fetchPaginas('/parlamentares/legislatura', $source);
        $legIds = [];
        foreach ($legislaturas as $l) {
            $id = upsertLegislatura($pdo, $source, $l);
            if ($id) $legIds[] = $id;
        }
        echo count($legIds) . " encontradas\n";

        // 3. Partidos ─────────────────────────────────────────────────────────
        echo "[sync]   [3/4] Partidos... ";
        $partidos = fetchPaginas('/parlamentares/partido', $source);
        foreach ($partidos as $p) {
            upsertPartido($pdo, $source, $p);
        }
        echo count($partidos) . " encontrados\n";

        // 4. Mandatos (por legislatura) ────────────────────────────────────────
        echo "[sync]   [4/4] Mandatos";
        $totalMandatos = 0;
        foreach ($legIds as $legId) {
            $mandatos = fetchPaginas('/parlamentares/mandato', $source, ['legislatura' => $legId]);
            foreach ($mandatos as $m) {
                upsertMandato($pdo, $source, $m);
            }
            $totalMandatos += count($mandatos);
            echo '.';
            usleep(DELAY_PAGINA_MS * 1000);
        }
        echo " {$totalMandatos} registros\n";

        $total = count($parlamentares);
        $pdo->prepare("
            UPDATE fonte_sincs
            SET status='ok', concluido_em=NOW(), total_parl=?, detalhes=NULL
            WHERE source_key=?
        ")->execute([$total, $source]);

        echo "[sync]   OK — {$total} parlamentares, " . count($legIds) . " legislaturas, " . count($partidos) . " partidos\n";

    } catch (Throwable $e) {
        $msg = substr($e->getMessage(), 0, 500);
        $pdo->prepare("
            UPDATE fonte_sincs
            SET status='erro', concluido_em=NOW(), detalhes=?
            WHERE source_key=?
        ")->execute([$msg, $source]);
        echo "\n[sync]   ERRO: {$msg}\n";
    }

    if ($i < $totalFontes) usleep(DELAY_FONTE_MS * 1000);
}

$duracao = round(microtime(true) - $inicio);
echo "\n[sync] Concluído em {$duracao}s.\n";

// Resumo final
$rows = $pdo->query("SELECT source_key, status, total_parl, concluido_em FROM fonte_sincs ORDER BY source_key")->fetchAll();
echo "\n┌─────────────────────┬──────────┬──────────┬─────────────────────┐\n";
echo "│ Fonte               │ Status   │ Parl.    │ Concluído em        │\n";
echo "├─────────────────────┼──────────┼──────────┼─────────────────────┤\n";
foreach ($rows as $r) {
    printf("│ %-19s │ %-8s │ %-8s │ %-19s │\n",
        $r['source_key'],
        $r['status'],
        $r['total_parl'],
        $r['concluido_em'] ?? '-'
    );
}
echo "└─────────────────────┴──────────┴──────────┴─────────────────────┘\n";
