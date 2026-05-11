<?php
/**
 * sync_camara_csvs.php
 *
 * Importa proposições, autores, temas e tramitações da Câmara Federal
 * a partir dos CSVs bulk de dados abertos (UTF-8, separador ';').
 *
 * Fonte: https://dadosabertos.camara.leg.br/arquivos/{tipo}/csv/{tipo}-{ano}.csv
 * Legislatura atual: 57ª (2023–2027)
 *
 * Uso:
 *   php database/sync_camara_csvs.php                              — tudo (2023–ano atual)
 *   php database/sync_camara_csvs.php --anos 2025,2026             — anos específicos
 *   php database/sync_camara_csvs.php --tipo proposicoes           — só detalhe
 *   php database/sync_camara_csvs.php --tipo autores,temas         — múltiplos tipos
 *   php database/sync_camara_csvs.php --csv /tmp/file.csv --tipo proposicoes --anos 2026
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

// ── Parsing de argumentos ─────────────────────────────────────────────────────

$anosArg  = null;
$tiposArg = null;
$csvLocal = null;

for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--anos' && isset($args[$i + 1])) { $anosArg  = $args[++$i]; }
    if ($args[$i] === '--tipo' && isset($args[$i + 1])) { $tiposArg = $args[++$i]; }
    if ($args[$i] === '--csv'  && isset($args[$i + 1])) { $csvLocal = $args[++$i]; }
}

// 57ª legislatura: 2023 até o ano corrente (máx 2027)
$anoAtual = (int)date('Y');
$anosLeg  = range(2023, min($anoAtual, 2027));

$anos  = $anosArg  ? array_map('intval', explode(',', $anosArg))  : $anosLeg;
$tipos = $tiposArg ? array_map('trim',   explode(',', $tiposArg)) : ['proposicoes', 'autores', 'temas', 'tramitacoes'];

$source = 'camara_federal';

// ── Carrega materia_ids que temos no banco ────────────────────────────────────

echo "[csvs] Carregando materia_ids do banco...\n";
$ids = $pdo->query(
    "SELECT DISTINCT materia_id FROM parl_materias
     WHERE source_key = 'camara_federal' AND materia_id IS NOT NULL AND materia_id > 0"
)->fetchAll(PDO::FETCH_COLUMN);

if (!$ids) {
    echo "[csvs] ERRO: nenhum materia_id em parl_materias para camara_federal.\n";
    echo "        Rode sync_estruturado.php antes.\n";
    exit(1);
}

$idSet = array_flip(array_map('intval', $ids));
echo "[csvs] " . count($idSet) . " proposições no banco.\n\n";

// ── Helpers ───────────────────────────────────────────────────────────────────

function extractId(string $uri): int
{
    return (int) basename(rtrim($uri, '/'));
}

function baixarCsv(string $url, string $label): string
{
    $tmp = sys_get_temp_dir() . '/camara_' . md5($url) . '.csv';

    if (file_exists($tmp) && filesize($tmp) > 100_000) {
        echo "  [{$label}] Cache local: {$tmp}\n";
        return $tmp;
    }

    echo "  [{$label}] Baixando {$url}...\n"; flush();
    $fh = fopen($tmp, 'w');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,          // stream direto para disco
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 600,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'KeekConecta/1.0',
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    $size = file_exists($tmp) ? filesize($tmp) : 0;

    // Aceita se HTTP 2xx e arquivo tem conteúdo (servidor pode fechar conexão
    // antes do Content-Length mas o CSV ainda é válido até o ponto recebido)
    if ($code < 200 || $code >= 300 || $size < 100_000) {
        echo "  [{$label}] ERRO HTTP {$code} (tamanho: {$size})\n";
        return '';
    }
    echo "  [{$label}] " . round($size / 1024 / 1024, 1) . " MB baixados.\n";
    return $tmp;
}

// ── Sincroniza proposições → parl_materias_detalhe ───────────────────────────
// CSV: id;uri;siglaTipo;numero;ano;codTipo;descricaoTipo;ementa;ementaDetalhada;
//      keywords;dataApresentacao;...;urlInteiroTeor;...;
//      ultimoStatus_dataHora;...;ultimoStatus_siglaOrgao;...;ultimoStatus_regime;
//      ultimoStatus_descricaoTramitacao;...;ultimoStatus_descricaoSituacao;...;
//      ultimoStatus_despacho;ultimoStatus_apreciacao;ultimoStatus_url

function sincProposicoes(PDO $pdo, array $anos, array $idSet, string $source, ?string $csvLocal): void
{
    $base = 'https://dadosabertos.camara.leg.br/arquivos/proposicoes/csv';

    $encerradas = [
        'transformado em norma jurídica', 'arquivada', 'retirada pelo autor',
        'prejudicada', 'rejeitada', 'vetada integralmente',
    ];

    $stIns = $pdo->prepare(
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

    $totalIns = 0;
    $totalUpd = 0;

    foreach ($anos as $ano) {
        $csvPath = $csvLocal ?: baixarCsv("{$base}/proposicoes-{$ano}.csv", "proposicoes/{$ano}");
        if (!$csvPath || !file_exists($csvPath)) continue;

        $f = fopen($csvPath, 'r');
        fgetcsv($f, 0, ';'); // header

        $ins  = 0;
        $lote = 0;
        $pdo->beginTransaction();

        while ($row = fgetcsv($f, 0, ';')) {
            $materiaId = (int)($row[0] ?? 0);
            if (!$materiaId || !isset($idSet[$materiaId])) continue;

            $sigla   = trim($row[2]  ?? '');
            $numero  = trim($row[3]  ?? '');
            $anoP    = (int)($row[4] ?? 0) ?: null;
            $descT   = trim($row[6]  ?? '');
            $ementa  = trim($row[7]  ?? '') ?: (trim($row[8] ?? '') ?: null);
            $kw      = trim($row[9]  ?? '') ?: null;
            $dataAp  = substr(trim($row[10] ?? ''), 0, 10) ?: null;
            $textoUrl= trim($row[15] ?? '') ?: null;
            $orgao   = trim($row[21] ?? '');
            $regime  = trim($row[23] ?? '');
            $tramDesc= trim($row[24] ?? '');
            $sit     = trim($row[26] ?? '');
            $desp    = trim($row[28] ?? '') ?: null;
            $desc    = trim("{$sigla} nº {$numero}/{$anoP}");
            $emTram  = !$sit || !in_array(mb_strtolower($sit), $encerradas);

            try {
                $stIns->execute([
                    $source, $materiaId, $sigla, $descT, $numero, $anoP, $ementa,
                    $dataAp, $sit ?: ($tramDesc ?: null), $orgao, $regime,
                    $desp, $kw, $emTram ? 1 : 0, $textoUrl, $desc,
                ]);
                $ins++;
                $lote++;
            } catch (PDOException $e) { /* ignora */ }

            if ($lote >= 500) {
                $pdo->commit();
                $pdo->beginTransaction();
                $lote = 0;
            }
        }
        $pdo->commit();
        fclose($f);

        $totalIns += $ins;
        echo "  [proposicoes/{$ano}] {$ins} registros inseridos/atualizados.\n";
    }

    echo "[proposicoes] Total: {$totalIns} registros.\n\n";
}

// ── Sincroniza autores ────────────────────────────────────────────────────────

function sincAutores(PDO $pdo, array $anos, array $idSet, string $source, ?string $csvLocal): void
{
    $base = 'https://dadosabertos.camara.leg.br/arquivos/proposicoesAutores/csv';

    $stIns = $pdo->prepare(
        "INSERT INTO parl_materias_autores
            (source_key, materia_id, nome_autor, tipo_autor, id_deputado_autor,
             sigla_partido, sigla_uf, ordem_assinatura, proponente)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );

    echo "[autores] Limpando registros anteriores...\n";
    $pdo->exec("DELETE FROM parl_materias_autores WHERE source_key = 'camara_federal'");

    $totalIns = 0;

    foreach ($anos as $ano) {
        $csvPath = $csvLocal ?: baixarCsv("{$base}/proposicoesAutores-{$ano}.csv", "autores/{$ano}");
        if (!$csvPath || !file_exists($csvPath)) continue;

        $f = fopen($csvPath, 'r');
        fgetcsv($f, 0, ';'); // header

        $ins  = 0;
        $lote = 0;
        $pdo->beginTransaction();

        while ($row = fgetcsv($f, 0, ';')) {
            $materiaId = (int)($row[0] ?? 0);
            if (!$materiaId || !isset($idSet[$materiaId])) continue;

            $depId = (int)($row[2] ?? 0) ?: null;
            $tipo  = trim($row[5] ?? '');
            $nome  = trim($row[6] ?? '');
            $part  = trim($row[7] ?? '');
            $uf    = trim($row[9] ?? '');
            $ordem = (int)($row[10] ?? 0);
            $prop  = (int)($row[11] ?? 0);

            try {
                $stIns->execute([$source, $materiaId, $nome, $tipo, $depId, $part, $uf, $ordem, $prop]);
                $ins++;
                $lote++;
            } catch (PDOException $e) { /* duplicata */ }

            if ($lote >= 500) {
                $pdo->commit();
                $pdo->beginTransaction();
                $lote = 0;
            }
        }
        $pdo->commit();
        fclose($f);

        $totalIns += $ins;
        echo "  [autores/{$ano}] {$ins} autores inseridos.\n";
    }

    echo "[autores] Total: {$totalIns} registros.\n\n";
}

// ── Sincroniza temas ──────────────────────────────────────────────────────────

function sincTemas(PDO $pdo, array $anos, array $idSet, string $source, ?string $csvLocal): void
{
    $base = 'https://dadosabertos.camara.leg.br/arquivos/proposicoesTemas/csv';

    $stIns = $pdo->prepare(
        "INSERT INTO parl_materias_temas
            (source_key, materia_id, cod_tema, tema, relevancia)
         VALUES (?,?,?,?,?)"
    );

    echo "[temas] Limpando registros anteriores...\n";
    $pdo->exec("DELETE FROM parl_materias_temas WHERE source_key = 'camara_federal'");

    $totalIns = 0;

    foreach ($anos as $ano) {
        $csvPath = $csvLocal ?: baixarCsv("{$base}/proposicoesTemas-{$ano}.csv", "temas/{$ano}");
        if (!$csvPath || !file_exists($csvPath)) continue;

        $f = fopen($csvPath, 'r');
        fgetcsv($f, 0, ';'); // header

        $ins  = 0;
        $lote = 0;
        $pdo->beginTransaction();

        while ($row = fgetcsv($f, 0, ';')) {
            $materiaId = extractId(trim($row[0] ?? ''));
            if (!$materiaId || !isset($idSet[$materiaId])) continue;

            $codTema    = (int)($row[4] ?? 0);
            $tema       = trim($row[5] ?? '');
            $relevancia = (int)($row[6] ?? 0);

            try {
                $stIns->execute([$source, $materiaId, $codTema, $tema, $relevancia]);
                $ins++;
                $lote++;
            } catch (PDOException $e) { /* duplicata */ }

            if ($lote >= 500) {
                $pdo->commit();
                $pdo->beginTransaction();
                $lote = 0;
            }
        }
        $pdo->commit();
        fclose($f);

        $totalIns += $ins;
        echo "  [temas/{$ano}] {$ins} classificações inseridas.\n";
    }

    echo "[temas] Total: {$totalIns} registros.\n\n";
}

// ── Sincroniza tramitações ────────────────────────────────────────────────────

function sincTramitacoes(PDO $pdo, array $anos, array $idSet, string $source, ?string $csvLocal): void
{
    $base = 'https://dadosabertos.camara.leg.br/arquivos/proposicoesTramitacoes/csv';

    $stIns = $pdo->prepare(
        "INSERT INTO parl_materias_tramitacao
            (source_key, materia_id, sequencia, data_tramitacao, destino_str, regime, status_str, texto, url)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );

    echo "[tramitacoes] Limpando registros anteriores...\n";
    $pdo->exec("DELETE FROM parl_materias_tramitacao WHERE source_key = 'camara_federal'");

    $totalIns = 0;

    foreach ($anos as $ano) {
        $csvPath = $csvLocal ?: baixarCsv("{$base}/proposicoesTramitacoes-{$ano}.csv", "tramitacoes/{$ano}");
        if (!$csvPath || !file_exists($csvPath)) continue;

        $f = fopen($csvPath, 'r');
        fgetcsv($f, 0, ';'); // header

        $ins  = 0;
        $lote = 0;
        $pdo->beginTransaction();

        while ($row = fgetcsv($f, 0, ';')) {
            $materiaId = extractId(trim($row[3] ?? ''));
            if (!$materiaId || !isset($idSet[$materiaId])) continue;

            $data   = substr(trim($row[1] ?? ''), 0, 10) ?: null;
            $seq    = (int)($row[2] ?? 0);
            $orgao  = trim($row[7]  ?? '');
            $regime = trim($row[9]  ?? '');
            $desc   = trim($row[10] ?? '');
            $desp   = trim($row[12] ?? '') ?: null;
            $url    = trim($row[13] ?? '') ?: null;

            try {
                $stIns->execute([$source, $materiaId, $seq, $data, $orgao, $regime, $desc, $desp, $url]);
                $ins++;
                $lote++;
            } catch (PDOException $e) { /* duplicata */ }

            if ($lote >= 500) {
                $pdo->commit();
                $pdo->beginTransaction();
                $lote = 0;
            }
        }
        $pdo->commit();
        fclose($f);

        $totalIns += $ins;
        echo "  [tramitacoes/{$ano}] {$ins} registros inseridos.\n";
    }

    echo "[tramitacoes] Total: {$totalIns} registros.\n\n";
}

// ── Execução ──────────────────────────────────────────────────────────────────

$inicio = microtime(true);

echo "Anos: " . implode(', ', $anos) . "\n";
echo "Tipos: " . implode(', ', $tipos) . "\n\n";

if (in_array('proposicoes',  $tipos)) sincProposicoes($pdo,  $anos, $idSet, $source, $csvLocal);
if (in_array('autores',      $tipos)) sincAutores($pdo,      $anos, $idSet, $source, $csvLocal);
if (in_array('temas',        $tipos)) sincTemas($pdo,        $anos, $idSet, $source, $csvLocal);
if (in_array('tramitacoes',  $tipos)) sincTramitacoes($pdo,  $anos, $idSet, $source, $csvLocal);

$dur = round(microtime(true) - $inicio);
echo "Concluído em {$dur}s (" . round($dur / 60, 1) . " min).\n";
