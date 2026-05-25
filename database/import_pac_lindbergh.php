<?php
/**
 * import_pac_lindbergh.php
 *
 * Importa dados do Novo PAC (CSV do Portal do Orçamento) para a tabela parl_pac,
 * vinculando ao parlamentar Lindbergh Farias (id=517, sapl_id=74858).
 *
 * Formato do CSV (34 colunas, separador vírgula, delimitador aspas):
 *   0  Ano
 *   1  Órgão
 *   2  Unidade Orçamentária
 *   3  Ação
 *   4  Localizador
 *   5  Plano Orçamentário
 *   6  Programa
 *   7  Esfera
 *   8  Função
 *   9  Subfunção
 *  10  IDUso ... 15 Tipo Crédito
 *  16  Projeto de Lei
 *  17  Dotação Inicial
 *  18  Dotação Atual
 *  19  Empenhado
 *  20  Liquidado
 *  21  Pago Exercício
 *  22-31 RAP fields
 *  32  Liquidado por Inscrição em RAP Não Processado
 *  33  Pago Exercício + RAP Pago  ← usamos este como "pago total"
 *
 * Uso:
 *   php database/import_pac_lindbergh.php --csv /caminho/novo_pac_RJ.csv
 *   php database/import_pac_lindbergh.php --csv /caminho/novo_pac_RJ.csv --truncate
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

$csvPath  = null;
$truncate = false;
foreach ($args as $i => $a) {
    if ($a === '--csv'      && isset($args[$i + 1])) $csvPath  = $args[$i + 1];
    if ($a === '--truncate')                          $truncate = true;
}

if (!$csvPath || !file_exists($csvPath)) {
    echo "Uso: php database/import_pac_lindbergh.php --csv /caminho/novo_pac_RJ.csv [--truncate]\n";
    exit(1);
}

$PARLAMENTAR_ID = 74858; // Lindbergh Farias (sapl_id = p.id no frontend)

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Converte valor monetário do CSV para float.
 * O CSV usa vírgula como separador de milhar (ex: "545,283" = 545283).
 */
function toDecimal(string $v): float {
    $v = trim($v);
    if ($v === '' || $v === '0') return 0.0;

    // Remove espaços, R$, etc.
    $v = preg_replace('/[^\d,.]/', '', $v);

    // Detecta formato:
    $hasDot   = strpos($v, '.') !== false;
    $hasComma = strpos($v, ',') !== false;

    if ($hasDot && $hasComma) {
        $lastDot   = strrpos($v, '.');
        $lastComma = strrpos($v, ',');
        if ($lastComma > $lastDot) {
            // BR: "1.234,56" → remove pontos, troca vírgula por ponto
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } else {
            // US: "1,234.56" → remove vírgulas
            $v = str_replace(',', '', $v);
        }
    } elseif ($hasComma) {
        $afterComma = substr($v, strrpos($v, ',') + 1);
        if (strlen($afterComma) <= 2) {
            // BR decimal: "1234,56"
            $v = str_replace(',', '.', $v);
        } else {
            // US milhar: "545,283" ou "1,234,567" → remove vírgulas
            $v = str_replace(',', '', $v);
        }
    }
    // Só pontos: trata como inteiro ou decimal US padrão
    return (float)$v;
}

/**
 * Remove o prefixo numérico/código de campos como "36000 - Ministério da Saúde"
 * → "Ministério da Saúde"
 */
function stripCode(string $s): string {
    return trim((string)preg_replace('/^\S+ - /', '', trim($s)));
}

/**
 * Extrai município e UF do campo Localizador.
 * Exemplos após decodificação:
 *   "3282 - No Município de Belford Roxo - RJ"  → Belford Roxo / RJ
 *   "6526 - No Município de Belford Roxo - RJ (Hospital Geral)" → Belford Roxo / RJ
 *   "0033 - No Estado do Rio de Janeiro"          → '' / RJ
 *   "0030 - Na Região Sudeste"                    → '' / ''
 */
function extractLocInfo(string $localizador): array {
    static $stateMap = [
        'acre'=>'AC','alagoas'=>'AL','amapá'=>'AP','amazonas'=>'AM',
        'bahia'=>'BA','ceará'=>'CE','distrito federal'=>'DF','espírito santo'=>'ES',
        'goiás'=>'GO','maranhão'=>'MA','mato grosso do sul'=>'MS','mato grosso'=>'MT',
        'minas gerais'=>'MG','pará'=>'PA','paraíba'=>'PB','paraná'=>'PR',
        'pernambuco'=>'PE','piauí'=>'PI','rio de janeiro'=>'RJ',
        'rio grande do norte'=>'RN','rio grande do sul'=>'RS','rondônia'=>'RO',
        'roraima'=>'RR','santa catarina'=>'SC','são paulo'=>'SP','sergipe'=>'SE',
        'tocantins'=>'TO',
    ];

    // Remove prefixo código: "3282 - " ou "@ - "
    $loc = trim((string)preg_replace('/^\S+ - /', '', trim($localizador)));

    // "No Município de CITY - UF" (com possível sufixo entre parênteses)
    if (preg_match('/No Munic[íi]pio de (.+?) - ([A-Z]{2})/iu', $loc, $m)) {
        return ['municipio' => mb_strtoupper(trim($m[1])), 'uf' => strtoupper($m[2])];
    }

    // "No Estado do/da/de STATE" → mapeia para sigla
    if (preg_match('/No Estado d[aoe] (.+?)(?:\s*[(-]|$)/iu', $loc, $m)) {
        $key = mb_strtolower(trim($m[1]));
        return ['municipio' => '', 'uf' => $stateMap[$key] ?? ''];
    }

    // "Na Região X" ou outros → sem localidade específica
    return ['municipio' => '', 'uf' => ''];
}

// ── Lê e decodifica o CSV ────────────────────────────────────────────────────

echo "[pac] Lendo CSV: {$csvPath}\n";

$raw = file_get_contents($csvPath);
if ($raw === false) {
    echo "[pac] Erro ao ler o arquivo.\n";
    exit(1);
}

// Arquivo já em UTF-8; normaliza BOM se presente
$content = ltrim($raw, "\xEF\xBB\xBF");

// Escreve em stream de memória para usar fgetcsv (suporta campos multilinha)
$mem = fopen('php://memory', 'r+');
fwrite($mem, $content);
rewind($mem);

$rowNum  = 0;
$skipped = 0;
$aggMap  = []; // chave agregação → dados somados

while (($cols = fgetcsv($mem, 0, ',', '"')) !== false) {
    $rowNum++;

    // Primeira linha lógica = cabeçalho (pode ser multilinha dentro de aspas)
    if ($rowNum === 1) {
        echo "[pac] Cabeçalho lido (" . count($cols) . " colunas).\n";
        continue;
    }

    if (count($cols) < 34) {
        $skipped++;
        continue;
    }

    $get = fn(int $i) => trim($cols[$i] ?? '');

    $ano        = (int)$get(0) ?: 2025;
    $orgao      = stripCode($get(1));
    $acao       = $get(3);   // mantém código: "8535 - Estruturação..."
    $locRaw     = $get(4);
    $programa   = $get(6);
    $funcao     = stripCode($get(8));
    $subfuncao  = stripCode($get(9));

    $dotIni  = toDecimal($get(17));
    $dotAtual = toDecimal($get(18));
    $emp     = toDecimal($get(19));
    $liq     = toDecimal($get(20));
    $pago    = toDecimal($get(33)); // Pago Exercício + RAP Pago

    $locInfo = extractLocInfo($locRaw);

    // Chave de agregação: agrupa linhas com mesma ação/localizador/função
    // (diferentes fontes/modalidades somam em um único registro)
    $key = implode('|', [$ano, $orgao, $acao, $locRaw, $programa, $funcao, $subfuncao]);

    if (!isset($aggMap[$key])) {
        $aggMap[$key] = [
            'parlamentar_id' => $PARLAMENTAR_ID,
            'ano'            => $ano,
            'orgao'          => $orgao,
            'acao'           => $acao,
            'localizador'    => $locRaw,
            'municipio'      => $locInfo['municipio'],
            'uf'             => $locInfo['uf'],
            'programa'       => $programa,
            'funcao'         => $funcao,
            'subfuncao'      => $subfuncao,
            'dotacao_inicial' => 0.0,
            'dotacao_atual'   => 0.0,
            'empenhado'       => 0.0,
            'liquidado'       => 0.0,
            'pago'            => 0.0,
        ];
    }

    $aggMap[$key]['dotacao_inicial'] += $dotIni;
    $aggMap[$key]['dotacao_atual']   += $dotAtual;
    $aggMap[$key]['empenhado']       += $emp;
    $aggMap[$key]['liquidado']       += $liq;
    $aggMap[$key]['pago']            += $pago;
}

fclose($mem);

$records = array_values($aggMap);
echo "[pac] Linhas CSV lidas: {$rowNum} | Puladas: {$skipped} | Registros agregados: " . count($records) . "\n";

// Mostra amostra para conferência
if ($records) {
    $sample = $records[0];
    echo "[pac] Amostra[0]: ano={$sample['ano']} orgao={$sample['orgao']} municipio={$sample['municipio']} uf={$sample['uf']}\n";
    echo "[pac] Valores: dot_atual={$sample['dotacao_atual']} emp={$sample['empenhado']} pago={$sample['pago']}\n";
}

if (empty($records)) {
    echo "[pac] Nenhum registro encontrado. Verifique o arquivo.\n";
    exit(1);
}

// ── Persiste ─────────────────────────────────────────────────────────────────

if ($truncate) {
    $pdo->exec("DELETE FROM parl_pac WHERE parlamentar_id = {$PARLAMENTAR_ID}");
    echo "[pac] Registros anteriores removidos.\n";
}

$sql = "INSERT INTO parl_pac
    (parlamentar_id, ano, orgao, acao, localizador, municipio, uf,
     programa, funcao, subfuncao, dotacao_inicial, dotacao_atual,
     empenhado, liquidado, pago)
    VALUES
    (:parlamentar_id, :ano, :orgao, :acao, :localizador, :municipio, :uf,
     :programa, :funcao, :subfuncao, :dotacao_inicial, :dotacao_atual,
     :empenhado, :liquidado, :pago)";

$stmt = $pdo->prepare($sql);

$inserted = 0;
foreach ($records as $r) {
    $stmt->execute($r);
    $inserted++;
}

echo "[pac] Inseridos: {$inserted} registros.\n";
echo "[pac] Concluído.\n";
