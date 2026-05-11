<?php
/**
 * sync_emendas_camara.php
 *
 * Importa emendas parlamentares do Portal da Transparência (CGU).
 * Fonte: https://portaltransparencia.gov.br/download-de-dados/emendas-parlamentares
 *
 * Fluxo:
 *   1. Baixa EmendasParlamentares.zip do Portal da Transparência
 *   2. Extrai o CSV principal
 *   3. Normaliza nomes e linka deputados por nome_parlamentar
 *   4. Insere em parl_emendas (tabela é truncada e repovoada)
 *
 * Uso:
 *   php database/sync_emendas_camara.php              — importa tudo
 *   php database/sync_emendas_camara.php --ano 2024   — filtra por ano
 *   php database/sync_emendas_camara.php --csv /path  — usa CSV local já extraído
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

$anoFiltro = null;
$csvLocal  = null;
foreach ($args as $i => $a) {
    if ($a === '--ano'  && isset($args[$i + 1])) $anoFiltro = (int)$args[$i + 1];
    if ($a === '--csv'  && isset($args[$i + 1])) $csvLocal  = $args[$i + 1];
}

$source = 'camara_federal';

// ── Baixa e extrai o CSV ──────────────────────────────────────────────────────

$csvPath = $csvLocal;

if (!$csvPath) {
    $zipUrl = 'https://portaltransparencia.gov.br/download-de-dados/emendas-parlamentares/2025';
    $tmpZip = sys_get_temp_dir() . '/emendas_camara.zip';
    $tmpDir = sys_get_temp_dir() . '/emendas_camara/';

    echo "[emendas] Baixando ZIP do Portal da Transparência...\n";
    $ch = curl_init($zipUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || !$data) {
        echo "[emendas] ERRO: HTTP {$code} ao baixar o ZIP.\n";
        exit(1);
    }
    file_put_contents($tmpZip, $data);
    echo "[emendas] " . round(strlen($data) / 1024 / 1024, 1) . " MB baixados.\n";

    // Extrai via PowerShell (ZipArchive pode não estar disponível)
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);
    $cmd = "powershell -Command \"Expand-Archive -Path '{$tmpZip}' -DestinationPath '{$tmpDir}' -Force\"";
    shell_exec($cmd);

    $csvPath = $tmpDir . 'EmendasParlamentares.csv';
    if (!file_exists($csvPath)) {
        echo "[emendas] ERRO: CSV não encontrado após extração.\n";
        exit(1);
    }
    echo "[emendas] Extraído: {$csvPath}\n";
}

// ── Mapa nome normalizado → sapl_id ──────────────────────────────────────────

function normNome(string $s): string
{
    $s = mb_strtoupper($s, 'UTF-8');
    $from = ['Á','À','Â','Ã','Ä','É','È','Ê','Ë','Í','Ì','Î','Ï','Ó','Ò','Ô','Õ','Ö','Ú','Ù','Û','Ü','Ç','Ñ'];
    $to   = ['A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','C','N'];
    return trim(str_replace($from, $to, $s));
}

$stParl = $pdo->query(
    "SELECT sapl_id, nome_parlamentar, nome_completo
     FROM parl_parlamentares WHERE source_key = 'camara_federal'"
);
$nomeMap = [];
foreach ($stParl->fetchAll(PDO::FETCH_ASSOC) as $p) {
    foreach ([$p['nome_parlamentar'], $p['nome_completo']] as $n) {
        if ($n) $nomeMap[normNome($n)] = (int)$p['sapl_id'];
    }
}
echo "[emendas] " . count($nomeMap) . " chaves de nome carregadas.\n";

// ── Limpa tabela e reinsere ───────────────────────────────────────────────────

if ($anoFiltro) {
    $pdo->prepare("DELETE FROM parl_emendas WHERE source_key=? AND ano=?")->execute([$source, $anoFiltro]);
    echo "[emendas] Limpando ano {$anoFiltro}...\n";
} else {
    $pdo->prepare("DELETE FROM parl_emendas WHERE source_key=?")->execute([$source]);
    echo "[emendas] Tabela limpa — reimportando tudo.\n";
}

$stIns = $pdo->prepare(
    "INSERT INTO parl_emendas
        (source_key, parlamentar_id, emenda_cod, numero, ano, tipo,
         localidade, funcao, subfuncao, orgao, acao, programa,
         valor_dotacao, valor_empenhado, valor_liquidado, valor_pago, descricao)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
);

// ── Processa CSV ──────────────────────────────────────────────────────────────

$f = fopen($csvPath, 'r');
fgetcsv($f, 0, ';'); // pula header

$inicio    = microtime(true);
$inseridos = 0;
$ignorados = 0;
$semMatch  = 0;
$linha     = 1;

while ($row = fgetcsv($f, 0, ';')) {
    $linha++;
    // Converte encoding (Windows-1252 → UTF-8)
    $row = array_map(fn($v) => mb_convert_encoding($v, 'UTF-8', 'Windows-1252'), $row);

    // Mapeamento real do CSV (EmendasParlamentares.csv do Portal da Transparência):
    // 0=CodEmenda 1=Ano 2=Tipo 3=CodAutor 4=NomeAutor 5=NumeroEmenda
    // 6=Localidade 8=Município 10=UF 11=Região 13=NomeFunção 15=NomeSubfunção
    // 17=NomePrograma 19=NomeAção 22=Empenhado 23=Liquidado 24=Pago
    // Nota: o CSV não possui coluna de valor_dotacao.
    $codEmenda = trim($row[0]  ?? '');
    $ano       = (int)($row[1]  ?? 0);
    $tipo      = trim($row[2]  ?? '');
    $codAutor  = trim($row[3]  ?? '');
    $nomeAutor = trim($row[4]  ?? '');
    $numero    = trim($row[5]  ?? '');
    $localidade= trim($row[6]  ?? '');
    $orgao     = trim($row[11] ?? '');  // Região (Nordeste, Sudeste...)
    $funcao    = trim($row[13] ?? '');  // Nome Função
    $subfuncao = trim($row[15] ?? '');  // Nome Subfunção
    $programa  = trim($row[17] ?? '');  // Nome Programa
    $acao      = trim($row[19] ?? '');  // Nome Ação
    $valEmp    = (float)str_replace(',', '.', str_replace('.', '', $row[22] ?? '0'));
    $valLiq    = (float)str_replace(',', '.', str_replace('.', '', $row[23] ?? '0'));
    $valPag    = (float)str_replace(',', '.', str_replace('.', '', $row[24] ?? '0'));

    // Filtra: só emendas individuais com autor identificado
    if (!$ano || ($anoFiltro && $ano !== $anoFiltro)) continue;
    if (!str_contains(strtolower($tipo), 'individual')) continue;
    if ($nomeAutor === '' || $nomeAutor === 'Sem informação') { $ignorados++; continue; }

    // Linka por nome normalizado
    $normAutor = normNome($nomeAutor);
    $parlId    = $nomeMap[$normAutor] ?? null;
    if (!$parlId) { $semMatch++; continue; }

    $descricao = "Emenda nº {$numero}/{$ano}" . ($localidade ? " — {$localidade}" : '');

    try {
        $stIns->execute([
            $source, $parlId,
            $codEmenda !== 'Sem informação' ? $codEmenda : '',
            $numero, $ano, $tipo,
            $localidade, $funcao, $subfuncao,
            $orgao, $acao, $programa,
            0, $valEmp, $valLiq, $valPag, $descricao,  // valor_dotacao não existe no CSV
        ]);
        $inseridos++;
    } catch (PDOException $e) {
        // ignora duplicatas
    }

    if ($inseridos % 2000 === 0 && $inseridos > 0) {
        echo "  {$inseridos} inseridas...\n"; flush();
    }
}
fclose($f);

$dur = round(microtime(true) - $inicio);
echo "\n[emendas] Concluído em {$dur}s:\n";
echo "  Inseridas:  {$inseridos}\n";
echo "  Sem match:  {$semMatch} (deputados não encontrados no banco)\n";
echo "  Ignoradas:  {$ignorados} (sem autor identificado)\n";
