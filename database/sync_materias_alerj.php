<?php
/**
 * sync_materias_alerj.php
 *
 * Sincroniza matérias (projetos de lei, PECs, etc.) e relatórias dos
 * deputados estaduais da ALERJ via Lotus Notes Domino HTTP (scpro2327.nsf).
 *
 * Fonte: http://alerjln1.alerj.rj.gov.br/scpro2327.nsf/{slug}int?openform&expandview
 * Legislatura: 13ª (2023-2027) — banco scpro2327.nsf
 *
 * Relatórias: extraídas das linhas "Distribuição" de cada matéria.
 * Se o relator identificado for um deputado do nosso banco, insere relatoria.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$pdo    = Database::connect();
$inicio = microtime(true);
$SOURCE = 'alrj';
$BASE   = 'http://alerjln1.alerj.rj.gov.br';
$NSF    = 'scpro2327.nsf'; // 13ª Legislatura 2023-2027

// Limite de deputados para processar (null = todos)
$LIMIT  = isset($argv[1]) && is_numeric($argv[1]) ? (int)$argv[1] : null;

// ── Helpers ───────────────────────────────────────────────────────────────────

function curlGetM(string $url, int $timeout = 25): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $body) ? $body : '';
}

function toUtf8M(string $s): string {
    // O servidor ALERJ NSF envia charset=UTF-8 — sem conversão necessária
    return $s;
}

function normNomeM(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = str_replace(
        ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ'],
        ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'],
        $s
    );
    $s = preg_replace('/[^a-z\s]/', '', $s);
    return preg_replace('/\s+/', ' ', trim($s));
}

function toNsfSlug(string $nome): string {
    return preg_replace('/[^a-z]/', '', normNomeM($nome));
}

// Converte "MM/DD/YYYY" → "YYYY-MM-DD"
function parseDate(string $d): ?string {
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($d), $m)) {
        return "{$m[3]}-{$m[1]}-{$m[2]}";
    }
    return null;
}

// Mapeamento de tipo completo → sigla
$TIPO_SIGLA = [
    'proposta de emenda constitucional'  => 'PEC',
    'projeto de lei'                     => 'PL',
    'projeto de lei complementar'        => 'PLC',
    'projeto de decreto legislativo'     => 'PDL',
    'projeto de resolucao'               => 'PRL',
    'projeto de resolucao legislativa'   => 'PRL',
    'projeto de complementar'            => 'PLC',
    'requerimento'                       => 'REQ',
    'indicacao'                          => 'IND',
    'indicação'                          => 'IND',
    'mensagem'                           => 'MSG',
    'mocao'                              => 'MOC',
    'proposta de fiscalizacao e controle'=> 'PFC',
    'voto de louvor'                     => 'VL',
    'voto de pesar'                      => 'VP',
    'emenda substitutiva'                => 'ES',
    'emenda aditiva'                     => 'EA',
];

function tipoSigla(string $tipoNome, array $map): string {
    $norm = normNomeM($tipoNome);
    if (isset($map[$norm])) return $map[$norm];
    if (str_contains($norm, 'complementar'))         return 'PLC';
    if (str_contains($norm, 'emenda constitucional')) return 'PEC';
    if (str_contains($norm, 'projeto de lei'))        return 'PL';
    if (str_contains($norm, 'requerimento'))          return 'REQ';
    if (str_contains($norm, 'indicac'))               return 'IND';
    if (str_contains($norm, 'decreto legislativo'))   return 'PDL';
    if (str_contains($norm, 'resoluc'))               return 'PRL';
    return mb_strtoupper(mb_substr(trim($tipoNome), 0, 10));
}

// ── 1. Carrega deputados ALERJ ─────────────────────────────────────────────────

$deps = $pdo->query(
    "SELECT pp.sapl_id, pp.nome_parlamentar, pp.nome_completo
     FROM parl_parlamentares pp
     WHERE pp.source_key = '{$SOURCE}' AND pp.ativo = 1
     ORDER BY pp.nome_parlamentar"
)->fetchAll(PDO::FETCH_ASSOC);

if ($LIMIT) $deps = array_slice($deps, 0, $LIMIT);
echo "[materias] " . count($deps) . " deputados a processar\n";

// Mapa normNome → sapl_id (para matching de relatores)
$normToId = [];
foreach ($deps as $d) {
    $normToId[normNomeM($d['nome_parlamentar'])] = $d['sapl_id'];
    if ($d['nome_completo']) $normToId[normNomeM($d['nome_completo'])] = $d['sapl_id'];
}

// ── 2. Limpa matérias e relatórias anteriores desta fonte ─────────────────────

$delM = $pdo->exec("DELETE FROM parl_materias WHERE source_key='{$SOURCE}'");
$delR = $pdo->exec("DELETE FROM parl_relatorias WHERE source_key='{$SOURCE}'");
echo "[materias] {$delM} matérias e {$delR} relatórias anteriores removidas\n\n";

// ── 3. Prepared statements ────────────────────────────────────────────────────

$stMateria = $pdo->prepare(
    "INSERT INTO parl_materias
     (source_key, sapl_id, tipo_sigla, numero, ano, ementa, data_apresentacao, situacao, descricao, primeiro_autor)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);

$stRelatoria = $pdo->prepare(
    "INSERT INTO parl_relatorias
     (source_key, sapl_id, materia_str, comissao_str, data_designacao)
     VALUES (?, ?, ?, ?, ?)"
);

// ── 4. Parseia view de matérias de um deputado ────────────────────────────────

function parseMateriaView(string $html, array $tipoSiglaMap): array {
    $html     = toUtf8M($html);
    $results  = []; // ['tipo', 'numero', 'ano', 'ementa', 'situacao', 'date', 'distribuicoes']

    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $rows, PREG_SET_ORDER);

    $currentTipo   = '';
    $currentCode   = '';
    $currentBill   = null;
    $passedHeader  = false;

    foreach ($rows as $row) {
        preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $row[1], $cells);
        $cols = array_map(
            fn($c) => trim(strip_tags(html_entity_decode($c, ENT_HTML5, 'UTF-8'))),
            $cells[1]
        );
        $cols = array_values(array_filter($cols, fn($c) => strlen(trim($c)) > 0));
        if (empty($cols)) continue;

        $first = $cols[0];

        // Header row: "Cadastro de Proposições | Data Public | Autor(es)"
        if (count($cols) >= 3 && str_contains($first, 'Cadastro')) {
            $passedHeader = true;
            continue;
        }
        if (!$passedHeader) continue;

        // Linha de tipo de proposição (1 coluna, não é código numérico)
        if (count($cols) === 1 && !preg_match('/^\d{11}$/', $first)) {
            // Pode ser tipo de proposição
            $sig = tipoSigla($first, $tipoSiglaMap);
            if ($sig && !preg_match('/^(Distribui|Autor|Regime)/i', $first)) {
                // Salva bill anterior se houver
                if ($currentBill !== null) $results[] = $currentBill;
                $currentTipo = $sig;
                $currentCode = '';
                $currentBill = null;
            }
            continue;
        }

        // Linha de código da proposição (11 dígitos)
        if (count($cols) === 1 && preg_match('/^(\d{11})$/', $first, $cm)) {
            // Salva bill anterior
            if ($currentBill !== null) $results[] = $currentBill;
            $code = $cm[1];
            $ano    = (int)substr($code, 0, 4);
            $numero = ltrim(substr($code, 6), '0') ?: '0';
            $currentCode = $code;
            $currentBill = [
                'tipo'         => $currentTipo,
                'numero'       => $numero,
                'ano'          => $ano,
                'code'         => $code,
                'ementa'       => '',
                'situacao'     => '',
                'date'         => null,
                'distribuicoes'=> [],
            ];
            continue;
        }

        if (!$currentBill) continue;

        // Linha de ementa (3 colunas: ementa+code+committees | date | author)
        if (count($cols) >= 2 && str_contains($first, '=>')) {
            // Verifica se NÃO é linha de distribuição
            if (!preg_match('/^Distribui/i', $first)) {
                // Ementa row
                $parts = explode('=>', $first);
                $ementa = trim($parts[0]);
                // Situação: {comitês atribuídos} — conteúdo entre chaves
                $situacao = '';
                if (preg_match('/\{([^}]+)\}/', $first, $sm)) {
                    $situacao = trim(preg_replace('/\s{2,}/', ' ', $sm[1]));
                }
                $currentBill['ementa']   = $ementa;
                $currentBill['situacao'] = $situacao;
                // Data: segundo campo
                $currentBill['date'] = parseDate($cols[1] ?? '');
                continue;
            }

            // Linha de Distribuição
            // Formato: "Distribuição => code => committee => Relator: name => ..."
            $parts    = explode('=>', $first);
            $comissao = isset($parts[2]) ? trim($parts[2]) : '';
            $relator  = '';
            foreach ($parts as $p) {
                if (preg_match('/Relator:\s*(.+)/i', trim($p), $rm)) {
                    $relator = trim($rm[1]);
                    break;
                }
            }
            if ($comissao && $relator && !preg_match('/Sem Distribui/i', $relator)) {
                $currentBill['distribuicoes'][] = [
                    'comissao' => $comissao,
                    'relator'  => $relator,
                ];
            }
            continue;
        }
    }

    // Salva último bill
    if ($currentBill !== null) $results[] = $currentBill;

    return $results;
}

// ── 5. Loop pelos deputados ────────────────────────────────────────────────────

$totalMaterias   = 0;
$totalRelatorias = 0;
$skipped404      = [];

// Slugs que diferem do padrão auto-derivado
// Chave: sapl_id → valor: slug real no NSF
$SLUG_OVERRIDES = [
    460 => 'pedroricardo',  // Dr. Pedro Ricardo (auto seria "drpedroricardo")
    503 => 'yuri',          // Yuri Moura (NSF usa só primeiro nome)
];

foreach ($deps as $dep) {
    $saplId = $dep['sapl_id'];
    $nome   = $dep['nome_parlamentar'];
    $slug   = $SLUG_OVERRIDES[$saplId] ?? toNsfSlug($nome);

    $url  = "{$BASE}/{$NSF}/{$slug}int?openform&expandview";
    $html = curlGetM($url, 25);

    if (!$html) {
        $skipped404[] = "{$nome} (slug={$slug})";
        echo "  404/ERR [{$nome}]\n";
        usleep(200000);
        continue;
    }

    $bills = parseMateriaView($html, $TIPO_SIGLA);

    if (empty($bills)) {
        echo "  [{$saplId}] {$nome}: 0 matérias\n";
        usleep(200000);
        continue;
    }

    $nM = $nR = 0;
    foreach ($bills as $bill) {
        if (!$bill['tipo'] || !$bill['numero'] || !$bill['ano']) continue;

        $descricao = "{$bill['tipo']} {$bill['numero']}/{$bill['ano']}";
        $situacao  = mb_substr($bill['situacao'], 0, 300);
        $ementa    = mb_substr($bill['ementa'],   0, 65535);

        $stMateria->execute([
            $SOURCE,
            $saplId,
            $bill['tipo'],
            $bill['numero'],
            $bill['ano'],
            $ementa ?: null,
            $bill['date'],
            $situacao,
            $descricao,
            1, // primeiro_autor
        ]);
        $nM++;

        // Relatórias nas distribuições
        foreach ($bill['distribuicoes'] as $dist) {
            $relatorNorm = normNomeM($dist['relator']);
            // Match relator a um deputado do DB
            $relatorId = null;
            if (isset($normToId[$relatorNorm])) {
                $relatorId = $normToId[$relatorNorm];
            } else {
                // Match parcial
                foreach ($normToId as $n => $id) {
                    if (strlen($n) > 4 && (str_contains($relatorNorm, $n) || str_contains($n, $relatorNorm))) {
                        $relatorId = $id;
                        break;
                    }
                }
            }

            if ($relatorId) {
                $materiaStr = "{$bill['tipo']} {$bill['numero']}/{$bill['ano']}";
                $stRelatoria->execute([
                    $SOURCE,
                    $relatorId,
                    $materiaStr,
                    mb_substr($dist['comissao'], 0, 300),
                    $bill['date'],
                ]);
                $nR++;
            }
        }
    }

    echo "  [{$saplId}] {$nome}: {$nM} matérias, {$nR} relatórias\n";
    $totalMaterias   += $nM;
    $totalRelatorias += $nR;
    usleep(300000); // 300ms entre deputados
}

$dur = round(microtime(true) - $inicio);
echo "\n[materias] Concluído em {$dur}s\n";
echo "[materias] {$totalMaterias} matérias, {$totalRelatorias} relatórias inseridas\n";

if ($skipped404) {
    echo "[materias] " . count($skipped404) . " deputados com 404/erro (slug não encontrado):\n";
    foreach ($skipped404 as $s) echo "  - {$s}\n";
}
