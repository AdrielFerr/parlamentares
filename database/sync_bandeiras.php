<?php
/**
 * sync_bandeiras.php
 *
 * Baixa as bandeiras dos 27 estados brasileiros do Wikimedia Commons
 * e armazena em public/assets/bandeiras/{uf}.png
 *
 * Uso:
 *   php database/sync_bandeiras.php                  — baixa todas
 *   php database/sync_bandeiras.php SP RJ PA         — estados específicos
 *   php database/sync_bandeiras.php --force          — re-baixa mesmo se já existe
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

define('ROOT', dirname(__DIR__));

$flagMap = [
    'AC' => 'Bandeira_do_Acre.svg',
    'AL' => 'Bandeira_de_Alagoas.svg',
    'AM' => 'Bandeira_do_Amazonas.svg',
    'AP' => 'Bandeira_do_Amap%C3%A1.svg',
    'BA' => 'Bandeira_da_Bahia.svg',
    'CE' => 'Bandeira_do_Cear%C3%A1.svg',
    'DF' => 'Bandeira_do_Distrito_Federal_%28Brasil%29.svg',
    'ES' => 'Bandeira_do_Esp%C3%ADrito_Santo.svg',
    'GO' => 'Bandeira_de_Goi%C3%A1s.svg',
    'MA' => 'Bandeira_do_Maranh%C3%A3o.svg',
    'MG' => 'Bandeira_de_Minas_Gerais.svg',
    'MS' => 'Bandeira_de_Mato_Grosso_do_Sul.svg',
    'MT' => 'Bandeira_de_Mato_Grosso.svg',
    'PA' => 'Bandeira_do_Par%C3%A1.svg',
    'PB' => 'Bandeira_da_Para%C3%ADba.svg',
    'PE' => 'Bandeira_de_Pernambuco.svg',
    'PI' => 'Bandeira_do_Piau%C3%AD.svg',
    'PR' => 'Bandeira_do_Paran%C3%A1.svg',
    'RJ' => 'Bandeira_do_estado_do_Rio_de_Janeiro.svg',
    'RN' => 'Bandeira_do_Rio_Grande_do_Norte.svg',
    'RO' => 'Bandeira_de_Rond%C3%B4nia.svg',
    'RR' => 'Bandeira_de_Roraima.svg',
    'RS' => 'Bandeira_do_Rio_Grande_do_Sul.svg',
    'SC' => 'Bandeira_de_Santa_Catarina.svg',
    'SE' => 'Bandeira_de_Sergipe.svg',
    'SP' => 'Bandeira_do_estado_de_S%C3%A3o_Paulo.svg',
    'TO' => 'Bandeira_do_Tocantins.svg',
];

$outputDir = ROOT . '/public/assets/bandeiras';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
    echo "[bandeiras] Diretório criado: $outputDir\n\n";
}

$args  = array_slice($argv, 1);
$force = in_array('--force', $args);
$ufs   = array_map('strtoupper', array_values(array_filter($args, fn($a) => $a !== '--force')));
if ($ufs) {
    $flagMap = array_filter($flagMap, fn($k) => in_array($k, $ufs), ARRAY_FILTER_USE_KEY);
}

echo "[bandeiras] Estados: " . implode(', ', array_keys($flagMap)) . ($force ? ' [--force]' : '') . "\n\n";

$ok = $skip = $erro = 0;
$inicio = microtime(true);

foreach ($flagMap as $uf => $filename) {
    $outFile = "$outputDir/" . strtolower($uf) . ".png";

    if (!$force && file_exists($outFile) && filesize($outFile) > 500) {
        echo "  [SKIP] $uf\n";
        $skip++;
        continue;
    }

    $url = 'https://commons.wikimedia.org/wiki/Special:FilePath/' . $filename . '?width=140';
    echo "  [DOWN] $uf ... ";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'KeekConecta/1.0 (educational project)',
    ]);
    $data     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($data && $httpCode === 200) {
        file_put_contents($outFile, $data);
        echo "OK (" . round(strlen($data) / 1024, 1) . " KB)\n";
        $ok++;
    } else {
        echo "ERRO (HTTP $httpCode" . ($error ? ", $error" : '') . ")\n";
        $erro++;
    }

    usleep(300000); // 300 ms entre requests (respeita rate limit do Wikimedia)
}

$tempo = round(microtime(true) - $inicio, 1);
echo "\n[bandeiras] Concluído em {$tempo}s — OK: $ok | Pulados: $skip | Erros: $erro\n";
