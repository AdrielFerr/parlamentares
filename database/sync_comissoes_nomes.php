<?php
/**
 * Busca nomes completos das comissões do SAPL, atualiza parl_comissoes
 * e armazena detalhes de cada comissão no sapl_cache para exibição.
 *
 * Uso: php database/sync_comissoes_nomes.php [source]
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Acesso negado.'); }

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';
require APP  . '/Core/SaplApi.php';
require APP  . '/Models/SaplCache.php';

const TTL   = 720; // 30 dias
const DELAY = 150;

$pdo    = Database::connect();
$source = $argv[1] ?? 'alpb';

// ── 1. Busca lista de comissões ──────────────────────────────────────────────
echo "[comissoes] Buscando /comissoes/comissao/ ...\n";
$comissaoMap  = []; // id → full API response array
$page = 1;
do {
    $raw  = SaplApi::getRaw('/comissoes/comissao/', $source, ['page' => $page]);
    usleep(DELAY * 1000);
    $data = json_decode($raw, true) ?: [];
    foreach ($data['results'] ?? [] as $c) {
        $id = (int)($c['id'] ?? 0);
        if (!$id) continue;
        $comissaoMap[$id] = $c;
        // Cacheia o detalhe individual para o proxy servir
        SaplCache::set($source, "/comissoes/comissao/{$id}/", json_encode($c), TTL);
    }
    $totalPags = (int)($data['pagination']['total_pages'] ?? 1);
    $page++;
} while ($page <= $totalPags && $page <= 50);

echo "[comissoes] " . count($comissaoMap) . " comissões encontradas\n";

// ── 2. Busca composições (composicao_id → comissao_id) ──────────────────────
echo "[comissoes] Buscando /comissoes/composicao/ ...\n";
$composicaoMap = []; // composicao_id → comissao_id
$page = 1;
do {
    $raw  = SaplApi::getRaw('/comissoes/composicao/', $source, ['page' => $page]);
    usleep(DELAY * 1000);
    $data = json_decode($raw, true) ?: [];
    foreach ($data['results'] ?? [] as $comp) {
        $cId   = (int)($comp['id'] ?? 0);
        $comId = is_array($comp['comissao'] ?? null)
                   ? (int)($comp['comissao']['id'] ?? 0)
                   : (int)($comp['comissao'] ?? 0);
        if ($cId && $comId) {
            $composicaoMap[$cId] = $comId;
            // Cacheia cada composicao individualmente para sync_estruturado ler depois
            SaplCache::set($source, "/comissoes/composicao/{$cId}/", json_encode($comp), TTL);
        }
    }
    $totalPags = (int)($data['pagination']['total_pages'] ?? 1);
    $page++;
} while ($page <= $totalPags && $page <= 200);

echo "[comissoes] " . count($composicaoMap) . " composições mapeadas\n";

// ── 3. Atualiza parl_comissoes com nome e comissao_id ───────────────────────
echo "[comissoes] Atualizando parl_comissoes ...\n";

$stUpdate = $pdo->prepare(
    "UPDATE parl_comissoes SET comissao_str = ?, comissao_id = ?
     WHERE source_key = ? AND sapl_id = ? AND data_inicio = ?"
);

$rows = $pdo->query(
    "SELECT data FROM sapl_cache
     WHERE source = '$source'
       AND cache_key LIKE '/comissoes/participacao/%'
       AND expires_at > NOW()"
)->fetchAll(PDO::FETCH_COLUMN);

$updated = 0;
foreach ($rows as $raw) {
    $data = json_decode($raw, true) ?: [];
    foreach ($data['results'] ?? [] as $p) {
        $parlId     = (int)($p['parlamentar'] ?? 0);
        $composicao = (int)($p['composicao']  ?? 0);
        $dataInicio = $p['data_designacao'] ?? null;
        $strAtual   = $p['__str__'] ?? '';

        if (!$parlId || !$composicao || !$dataInicio) continue;

        // Resolve composicao_id → comissao_id (busca individual se necessário)
        if (!isset($composicaoMap[$composicao])) {
            $cr = json_decode(SaplApi::getRaw("/comissoes/composicao/{$composicao}/", $source, []), true) ?: [];
            usleep(DELAY * 1000);
            $comId = is_array($cr['comissao'] ?? null) ? (int)($cr['comissao']['id'] ?? 0) : (int)($cr['comissao'] ?? 0);
            if ($comId) $composicaoMap[$composicao] = $comId;
        }

        $comissaoId = $composicaoMap[$composicao] ?? null;
        if (!$comissaoId) continue;

        // Resolve comissao_id → dados (busca individual se necessário)
        if (!isset($comissaoMap[$comissaoId])) {
            $cr = json_decode(SaplApi::getRaw("/comissoes/comissao/{$comissaoId}/", $source, []), true) ?: [];
            usleep(DELAY * 1000);
            if (!empty($cr['nome'])) {
                $comissaoMap[$comissaoId] = $cr;
                SaplCache::set($source, "/comissoes/comissao/{$comissaoId}/", json_encode($cr), TTL);
            }
        }

        $comissao = $comissaoMap[$comissaoId] ?? null;
        if (!$comissao || !is_array($comissao)) continue;

        $sigla    = $comissao['sigla'] ?? '';
        $nome     = $comissao['nome']  ?? $comissao['__str__'] ?? '';
        $cargoStr = explode(' : ', $strAtual)[0] ?? '';
        $nomeFull = trim(($sigla ? $sigla . ' — ' : '') . $nome);
        $novoStr  = $cargoStr ? "{$cargoStr} : {$nomeFull}" : $nomeFull;

        $stUpdate->execute([$novoStr, $comissaoId, $source, $parlId, $dataInicio]);
        $updated += $stUpdate->rowCount();
    }
}

echo "[comissoes] {$updated} registros atualizados em parl_comissoes\n";
echo "Concluído.\n";
