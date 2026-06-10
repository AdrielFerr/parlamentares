<?php
/**
 * sync_comissoes_alerj.php
 *
 * Sincroniza membros de comissões permanentes (compcom.nsf) e temporárias
 * (comtemp.nsf) da ALERJ via Lotus Notes Domino HTTP.
 *
 * Fonte: http://alerjln1.alerj.rj.gov.br/
 * Limitação: o servidor Domino não suporta paginação (Start= resulta em
 * conexão fechada). Cada execução processa o lote inicial (~4 permanentes,
 * ~5 temporárias). Re-executar à medida que novas comissões sejam instaladas.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$pdo   = Database::connect();
$inicio = microtime(true);
$SOURCE = 'alrj';
$BASE   = 'http://alerjln1.alerj.rj.gov.br';

// ── Helpers ───────────────────────────────────────────────────────────────────

function curlGet(string $url, int $timeout = 25): string {
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

function toUtf8(string $s): string {
    // compcom.nsf e comtemp.nsf enviam Windows-1252 apesar de declarar UTF-8
    return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}

function normNome(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = str_replace(
        ['á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ'],
        ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'],
        $s
    );
    $s = preg_replace('/[^a-z\s]/', '', $s);
    return preg_replace('/\s+/', ' ', trim($s));
}

// Extrai prefixo do email ALERJ: "joaosilva@alerj.rj.gov.br" → "joaosilva"
function emailPrefix(string $email): string {
    $email = trim(strtolower($email));
    if (!str_contains($email, '@alerj')) return '';
    return explode('@', $email)[0];
}

// ── 1. Carrega deputados ALERJ para matching ──────────────────────────────────

$deps = $pdo->query(
    "SELECT sapl_id, nome_parlamentar, nome_completo FROM parl_parlamentares
     WHERE source_key = '{$SOURCE}' AND ativo = 1"
)->fetchAll(PDO::FETCH_ASSOC);

$normMap  = []; // normNome → sapl_id
$emailMap = []; // email_prefix (sem acentos/espaços) → sapl_id

foreach ($deps as $d) {
    $normParl = normNome($d['nome_parlamentar']);
    $normComp = normNome($d['nome_completo'] ?? '');
    // O email ALERJ é o normNome sem espaços
    $emailKey = str_replace(' ', '', $normParl);

    $normMap[$normParl]   = $d['sapl_id'];
    if ($normComp) $normMap[$normComp] = $d['sapl_id'];
    $emailMap[$emailKey]  = $d['sapl_id'];
}

echo "[comissoes] " . count($deps) . " deputados carregados\n";

// ── 2. Busca UNIDs via ReadViewEntries ────────────────────────────────────────

function getCommitteeUnids(string $base, string $nsf): array {
    $url  = "{$base}/{$nsf}";
    // viewUNID vem nos hrefs dos OpenDocument na lista HTML
    $html = curlGet("{$url}/ComCompInt?OpenForm", 20);
    if (!$html) {
        echo "  [{$nsf}] Falhou ao obter lista\n";
        return [];
    }
    preg_match('/href="[^"]*' . preg_quote($nsf, '/') . '\/([a-f0-9]{32})\//i', $html, $m);
    if (!$m) {
        echo "  [{$nsf}] ViewUNID não encontrado\n";
        return [];
    }
    $viewUNID = $m[1];
    echo "  [{$nsf}] viewUNID={$viewUNID}\n";

    $xml = curlGet("{$url}/{$viewUNID}?ReadViewEntries", 25);
    if (!$xml) {
        echo "  [{$nsf}] ReadViewEntries falhou\n";
        return [];
    }

    $result = [];
    $parsed = simplexml_load_string($xml);
    if (!$parsed) { echo "  [{$nsf}] XML inválido\n"; return []; }

    // Aceita campos de categoria pelo nome ou pelo primeiro campo de texto
    $catFields   = ['$20', 'ComissaoNome', 'NomeComissao', 'Nome'];
    $PLACEHOLDER = 'Comissão de'; // template vazio a ignorar

    $lastCatName = '';
    $lastCatPos  = '';
    foreach ($parsed->viewentry as $entry) {
        $pos  = (string)$entry['position'];
        $unid = strtoupper((string)$entry['unid']);

        // Procura categoria (entry sem UNID = category row)
        if (!$unid) {
            foreach ($entry->entrydata as $ed) {
                $catName = trim((string)($ed->text ?? ''));
                if (!$catName) continue;
                // Ignora categorias de Legislatura/Sessão (números ordinais)
                if (preg_match('/^\d+[ªº]/u', $catName)) continue;
                // Ignora template vazio
                if ($catName === $PLACEHOLDER) continue;
                $lastCatName = $catName; // já é UTF-8 do XML
                $lastCatPos  = $pos;
                break;
            }
            continue;
        }

        if (!$lastCatName) continue;

        // Verifica que este doc é filho direto da última categoria
        $parts     = explode('.', $pos);
        $parentPos = implode('.', array_slice($parts, 0, -1));
        if ($parentPos !== $lastCatPos) continue;

        $dataInicio = null;
        foreach ($entry->entrydata as $ed) {
            if ((string)$ed['name'] === 'Instalação' && $ed->datetime) {
                $dt = (string)$ed->datetime;
                if (strlen($dt) === 8) {
                    $dataInicio = substr($dt, 0, 4) . '-' . substr($dt, 4, 2) . '-' . substr($dt, 6, 2);
                }
            }
        }

        $result[] = [
            'unid'        => $unid,
            'nome'        => $lastCatName,
            'data_inicio' => $dataInicio,
            'nsf'         => $nsf,
        ];
        echo "  [{$nsf}] {$lastCatName} → {$unid}" . ($dataInicio ? " ({$dataInicio})" : '') . "\n";
    }
    return $result;
}

echo "\n[compcom] Buscando comissões permanentes...\n";
$permanentes = getCommitteeUnids($BASE, 'compcom.nsf');

echo "\n[comtemp] Buscando comissões temporárias...\n";
$temporarias = getCommitteeUnids($BASE, 'comtemp.nsf');

$allCommittees = array_merge($permanentes, $temporarias);
echo "\nTotal: " . count($allCommittees) . " comissões encontradas\n";

// Limpa todos os registros desta fonte antes de reinserir
$deleted = $pdo->exec("DELETE FROM parl_comissoes WHERE source_key='{$SOURCE}'");
echo "[comissoes] {$deleted} registros anteriores removidos\n";

// ── 3. Para cada comissão, busca o documento e parseia membros ────────────────

function matchDeputy(string $rawName, array $normMap, array $emailMap): ?int {
    // Remove prefixos de cargo: "Pres. ", "V. Pres. ", "1º ", "2º ", etc.
    $cleanName = preg_replace('/^(?:V\.\s*)?Pres\.\s*|^\d+[º°]\s*/u', '', $rawName);
    $cleanName = trim($cleanName);
    if (strlen($cleanName) < 3) return null;

    // Tenta email prefix primeiro
    $emailKey = str_replace(' ', '', normNome($cleanName));
    if (isset($emailMap[$emailKey])) return $emailMap[$emailKey];

    // Tenta normNome exato
    $norm = normNome($cleanName);
    if (isset($normMap[$norm])) return $normMap[$norm];

    // Match parcial: nome contido no mapa ou vice-versa
    foreach ($normMap as $n => $id) {
        if (strlen($n) > 4 && (str_contains($norm, $n) || str_contains($n, $norm))) {
            return $id;
        }
    }
    return null;
}

function parseCommitteeDoc(string $html): array {
    $html    = toUtf8($html);
    $entries = [];

    preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $rows, PREG_SET_ORDER);

    $section            = 'membros';
    $headerCount        = 0;
    $passedFirstHeader  = false; // Ignora tudo antes do 1º "DEPUTADO"

    foreach ($rows as $row) {
        preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $row[1], $cells);
        $cols = array_map(
            fn($c) => trim(strip_tags(html_entity_decode($c, ENT_HTML5, 'UTF-8'))),
            $cells[1]
        );
        $cols = array_values(array_filter($cols, fn($c) => strlen(trim($c)) > 0));
        if (empty($cols)) continue;

        $first = $cols[0];

        // Cabeçalho de seção "DEPUTADO"
        if (preg_match('/^DEPUTADO$/i', $first)) {
            $passedFirstHeader = true;
            $headerCount++;
            if ($headerCount >= 3) $section = 'suplentes';
            continue;
        }

        // Ignora linhas do cabeçalho do formulário (antes do 1º DEPUTADO)
        if (!$passedFirstHeader) continue;

        // Linhas de metadados de formulário: terminam com ":" ou são keywords
        if (preg_match('/:\s*$/', $first)) continue;          // ex: "Sessão Legislativa:"
        if (preg_match('/^@alerj/i', $first)) continue;       // email sem prefixo
        if (preg_match('/^(?:PARTIDO|Telefone|Ramal)/i', $first)) continue;

        // Detecta suplente por numeração ordinal (1º, 2ª, etc.)
        if (preg_match('/^\d+[º°ª]/u', $first)) {
            $section = 'suplentes';
        }

        // Detecta cargo Pres. ou V. Pres. — se sem nome, skip
        if (preg_match('/^(?:V\.\s*)?Pres\.\s*$/i', $first)) continue;

        // Extrai email (campo com '@alerj' e com prefixo)
        $email = '';
        foreach (array_reverse($cols) as $c) {
            if (str_contains($c, '@alerj') && strpos($c, '@') > 0) { $email = $c; break; }
        }

        $entries[] = [
            'rawName' => $first,
            'email'   => $email,
            'titular' => ($section !== 'suplentes') ? 1 : 0,
        ];
    }
    return $entries;
}

// ── Statements SQL ─────────────────────────────────────────────────────────────

$stInsert = $pdo->prepare(
    "INSERT INTO parl_comissoes (source_key, sapl_id, comissao_str, data_inicio, titular)
     VALUES (?, ?, ?, ?, ?)"
);

$totalInserted = 0;
$totalUnmatched = 0;

foreach ($allCommittees as $com) {
    $nsf     = $com['nsf'];
    $nome    = $com['nome'];
    $unid    = $com['unid'];
    $dataIni = $com['data_inicio'];

    $docHtml = curlGet("{$BASE}/{$nsf}/0/{$unid}?OpenDocument", 20);
    if (!$docHtml) {
        // Tenta com viewUNID (padrão dos links)
        $docHtml = curlGet(
            "{$BASE}/{$nsf}/ff39d0eb846ddbd6032567f3006de5e7/{$unid}?OpenDocument",
            20
        );
    }
    if (!$docHtml) {
        echo "  ERRO: não obteve documento {$unid} ({$nome})\n";
        continue;
    }

    $comInserted  = 0;
    $comUnmatched = 0;
    $seen = []; // evita duplicata por deputy

    foreach (parseCommitteeDoc($docHtml) as $m) {
        $rawName = $m['rawName'];
        $email   = $m['email'];

        if (strlen(normNome($rawName)) < 3) continue;

        // Tenta match por email primeiro, depois por nome
        $saplId = null;
        if ($email && ($prefix = emailPrefix($email))) {
            if (isset($emailMap[$prefix])) $saplId = $emailMap[$prefix];
        }
        if (!$saplId) {
            $saplId = matchDeputy($rawName, $normMap, $emailMap);
        }

        if (!$saplId) {
            echo "    ✗ sem match: [{$rawName}] ({$nome})\n";
            $comUnmatched++;
            continue;
        }

        if (isset($seen[$saplId])) continue;
        $seen[$saplId] = true;

        $stInsert->execute([$SOURCE, $saplId, $nome, $dataIni, $m['titular']]);
        $comInserted++;
    }

    echo "  [{$nsf}] {$nome}: {$comInserted} membros inseridos, {$comUnmatched} sem match\n";
    $totalInserted  += $comInserted;
    $totalUnmatched += $comUnmatched;
    usleep(300000); // 300ms entre docs
}

$dur = round(microtime(true) - $inicio);
echo "\n[comissoes] Concluído em {$dur}s — {$totalInserted} inseridos, {$totalUnmatched} sem match\n";
echo "[comissoes] Nota: apenas comissões com composição formalizada são sincronizadas.\n";
echo "[comissoes] Re-execute à medida que novas comissões forem instaladas.\n";
