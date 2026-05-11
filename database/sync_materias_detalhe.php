<?php
/**
 * sync_materias_detalhe.php
 *
 * Para cada proposição em parl_materias (camara_federal), busca:
 *   - Detalhe completo: /api/v2/proposicoes/{id}
 *   - Tramitação:       /api/v2/proposicoes/{id}/tramitacoes
 *
 * Armazena em parl_materias_detalhe e parl_materias_tramitacao.
 * Após o sync, zero chamadas de API ao vivo para abrir proposições.
 *
 * Uso:
 *   php database/sync_materias_detalhe.php              — somente camara_federal
 *   php database/sync_materias_detalhe.php --force      — resynca mesmo se já existe
 *   php database/sync_materias_detalhe.php --limit 500  — máx N proposições por execução
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$pdo  = Database::connect();
$args = array_slice($argv ?? [], 1);

$force = in_array('--force', $args);
$limit = 0;
foreach ($args as $i => $a) {
    if ($a === '--limit' && isset($args[$i + 1])) $limit = (int)$args[$i + 1];
}

$source = 'camara_federal';

// ── IDs únicos de proposições a sincronizar ───────────────────────────────────

$whereExtra = $force ? '' : '
    AND NOT EXISTS (
        SELECT 1 FROM parl_materias_detalhe d
        WHERE d.source_key = pm.source_key AND d.materia_id = pm.materia_id
    )';

$sql = "SELECT DISTINCT materia_id
        FROM parl_materias pm
        WHERE source_key = ? AND materia_id IS NOT NULL AND materia_id > 0
        {$whereExtra}
        ORDER BY materia_id DESC"
     . ($limit ? " LIMIT {$limit}" : '');

$st = $pdo->prepare($sql);
$st->execute([$source]);
$ids   = $st->fetchAll(PDO::FETCH_COLUMN);
$total = count($ids);

if (!$total) {
    echo "[detalhe] Nada a sincronizar. Rode sync_estruturado.php antes ou use --force.\n";
    exit(0);
}

echo "[detalhe] {$total} proposições para sincronizar" . ($force ? ' [--force]' : '') . "\n\n";

// ── HTTP helper ───────────────────────────────────────────────────────────────

function camaraGet(string $url): ?array
{
    static $ch = null;
    if (!$ch) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'KeekConecta/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($code === 429) {
        echo "   [rate-limit] aguardando 60s...\n"; flush();
        sleep(60);
        curl_setopt($ch, CURLOPT_URL, $url);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }
    if (!$body || $code < 200 || $code >= 300) return null;
    return json_decode($body, true) ?: null;
}

function strOf(mixed $v): string
{
    if (!$v) return '';
    if (is_string($v)) return trim($v);
    return trim($v['__str__'] ?? $v['descricao'] ?? $v['nome'] ?? '');
}

function toDate(?string $s): ?string
{
    if (!$s) return null;
    return substr($s, 0, 10);
}

// ── Prepared statements ───────────────────────────────────────────────────────

$stDet = $pdo->prepare(
    "INSERT INTO parl_materias_detalhe
        (source_key, materia_id, tipo_sigla, tipo_descricao, numero, ano, ementa,
         data_apresentacao, situacao, orgao_atual, regime_tramitacao,
         despacho_atual, palavras_chave, em_tramitacao, texto_url, descricao)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
        tipo_sigla=VALUES(tipo_sigla), tipo_descricao=VALUES(tipo_descricao),
        numero=VALUES(numero), ano=VALUES(ano), ementa=VALUES(ementa),
        data_apresentacao=VALUES(data_apresentacao), situacao=VALUES(situacao),
        orgao_atual=VALUES(orgao_atual), regime_tramitacao=VALUES(regime_tramitacao),
        despacho_atual=VALUES(despacho_atual), palavras_chave=VALUES(palavras_chave),
        em_tramitacao=VALUES(em_tramitacao), texto_url=VALUES(texto_url),
        descricao=VALUES(descricao), atualizado_em=NOW()"
);

$stDelTram = $pdo->prepare(
    "DELETE FROM parl_materias_tramitacao WHERE source_key=? AND materia_id=?"
);
$stTram = $pdo->prepare(
    "INSERT INTO parl_materias_tramitacao
        (source_key, materia_id, sequencia, data_tramitacao, status_str, destino_str, texto)
     VALUES (?,?,?,?,?,?,?)"
);

// ── Loop principal ────────────────────────────────────────────────────────────

$base   = 'https://dadosabertos.camara.leg.br/api/v2';
$inicio = microtime(true);
$ok     = 0;
$erros  = 0;

$encerradas = [
    'transformado em norma jurídica', 'arquivada', 'retirada pelo autor',
    'prejudicada', 'rejeitada', 'vetada integralmente',
];

foreach ($ids as $i => $materiaId) {
    $pos = $i + 1;
    if ($pos === 1 || $pos % 100 === 0 || $pos === $total) {
        $pct = round($pos / $total * 100);
        $dur = round(microtime(true) - $inicio);
        echo "[{$pos}/{$total} {$pct}% {$dur}s] ID {$materiaId}\n";
        flush();
    }

    try {
        // ── Detalhe ──────────────────────────────────────────────────────────
        $det = camaraGet("{$base}/proposicoes/{$materiaId}");
        if (!$det || empty($det['dados'])) { $erros++; usleep(100_000); continue; }

        $d      = $det['dados'];
        $status = $d['statusProposicao'] ?? [];
        $tipo   = $d['siglaTipo']        ?? '';
        $descT  = $d['descricaoTipo']    ?? '';
        $num    = (string)($d['numero']  ?? '');
        $ano    = (int)($d['ano']        ?? 0) ?: null;
        $sit    = $status['descricaoSituacao'] ?? '';
        $orgao  = $status['siglaOrgao']        ?? '';
        $regime = $status['regime']            ?? '';
        $desp   = $status['despacho']          ?? '';
        $kw     = $d['keywords']               ?? '';
        $emTram = !empty($status) && !in_array(mb_strtolower($sit), $encerradas);
        $pdf    = $d['urlTeorPDF'] ?? null;
        $ementa = $d['ementa']    ?? '';
        $dataAp = toDate($d['dataApresentacao'] ?? null);
        $desc   = trim("{$tipo} nº {$num}/{$ano}");

        $stDet->execute([
            $source, (int)$materiaId, $tipo, $descT, $num, $ano, $ementa,
            $dataAp, $sit, $orgao, $regime,
            $desp ?: null, $kw ?: null, $emTram ? 1 : 0, $pdf, $desc,
        ]);

        // ── Tramitação ───────────────────────────────────────────────────────
        $tram = camaraGet("{$base}/proposicoes/{$materiaId}/tramitacoes");
        $stDelTram->execute([$source, (int)$materiaId]);

        if ($tram && !empty($tram['dados'])) {
            $seq = count($tram['dados']);
            foreach ($tram['dados'] as $t) {
                $statusStr  = trim(($t['siglaOrgao'] ?? '') . ($t['descricaoSituacao'] ? ' — ' . $t['descricaoSituacao'] : ''));
                $destinoStr = $t['siglaOrgao'] ?? '';
                $textoStr   = $t['despacho']   ?? '';
                $dataTram   = toDate(substr($t['dataHora'] ?? '', 0, 10));

                $stTram->execute([
                    $source, (int)$materiaId, $seq,
                    $dataTram, $statusStr, $destinoStr, $textoStr ?: null,
                ]);
                $seq--;
            }
        }

        $ok++;
        usleep(200_000); // 200ms entre requisições
    } catch (Throwable $ex) {
        $erros++;
        echo "   ERRO [{$materiaId}] {$ex->getMessage()}\n";
    }
}

$dur = round(microtime(true) - $inicio);
echo "\n[detalhe] Concluído: {$ok} ok, {$erros} erros — {$dur}s (" . round($dur / 60, 1) . " min)\n";
