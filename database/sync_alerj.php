<?php
/**
 * sync_alerj.php
 *
 * Importa deputados estaduais do RJ (ALERJ, 13ª legislatura) via scraping.
 * Fonte: https://www.alerj.rj.gov.br/Deputados/RepresentacaoPartidaria
 * Fotos: /Uploads/PerfilDeputado/Imagem/...
 * Perfis: /Deputados/PerfilDeputado/{id}?Legislatura=20
 *
 * Uso:
 *   php database/sync_alerj.php              — importa dados, fotos e biografias
 *   php database/sync_alerj.php --force      — rebaixa fotos já existentes
 *   php database/sync_alerj.php --skip-fotos — apenas dados, sem baixar fotos
 *   php database/sync_alerj.php --skip-bio   — sem buscar biografia nos perfis
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$args      = array_slice($argv, 1);
$force     = in_array('--force', $args);
$skipFotos = in_array('--skip-fotos', $args);
$skipBio   = in_array('--skip-bio', $args);

const SOURCE   = 'alrj';
const UF       = 'RJ';
const BASE_URL = 'https://www.alerj.rj.gov.br';
const LIST_URL = 'https://www.alerj.rj.gov.br/Deputados/QuemSao';
const LEG_ID   = 20; // 13ª legislatura

$pdo = Database::connect();
$uploadDir = ROOT . '/public/uploads/parlamentares/' . SOURCE;
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$inicio = microtime(true);

// ── Helpers ───────────────────────────────────────────────────────────────────

function curlGet(string $url, int $timeout = 20): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING       => 'gzip',
        CURLOPT_HTTPHEADER     => ['Accept-Language: pt-BR,pt;q=0.9'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body && $code === 200) ? $body : null;
}

function toTitle(string $s): string {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

// ── 1. Busca página de representação partidária ───────────────────────────────

echo "[alerj] Buscando lista de deputados...\n";
$html = curlGet(LIST_URL);
if (!$html) {
    echo "[alerj] ERRO: Página indisponível\n";
    exit(1);
}

// ── 2. Parseia cards de deputados ─────────────────────────────────────────────
// Estrutura real por card:
//   <div class="controle_deputado">
//     <div class="imagem"><a href="/Deputados/PerfilDeputado/ID?Legislatura=20">
//       <img src="/Uploads/..." alt="NOME"></a></div>
//     <div class="descricao">
//       <div class="partido">SIGLA</div>
//       <div class="nome"><a href="...">NOME</a></div>
//     </div>
//   </div>

$deputies = [];

preg_match_all(
    '#<div\s+class="controle_deputado[^"]*">\s*'
    . '<div\s+class="imagem">\s*'
    . '<a\s+href="/Deputados/PerfilDeputado/(\d+)\?Legislatura=\d+">'
    . '<img\s+src="([^"]+)"\s+alt="([^"]*)"></a>\s*</div>\s*'
    . '<div\s+class="descricao">\s*'
    . '<div\s+class="partido">([^<]*)</div>#i',
    $html,
    $cards,
    PREG_SET_ORDER
);

foreach ($cards as $m) {
    $id = (int)$m[1];
    if (!$id) continue;
    $deputies[$id] = [
        'id'    => $id,
        'foto'  => $m[2],
        'nome'  => toTitle(trim($m[3])),
        'party' => html_entity_decode(trim($m[4]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
    ];
}

$total = count($deputies);
echo "[alerj] {$total} deputados encontrados\n\n";

if (!$deputies) {
    echo "[alerj] ERRO: Nenhum deputado parseado — HTML da ALERJ pode ter mudado.\n";
    echo "[alerj] Verifique manualmente: " . LIST_URL . "\n";
    exit(1);
}

// ── 3. Cria legislatura atual (13ª, 2023-2027) ───────────────────────────────
// LEG_ID=20 é o parâmetro Legislatura= da URL da ALERJ

$pdo->prepare("
    INSERT INTO parl_legislaturas
        (source_key, sapl_id, numero, data_inicio, data_fim, sincronizado_em)
    VALUES (?, ?, 13, '2023-01-11', '2027-12-31', NOW())
    ON DUPLICATE KEY UPDATE
        numero      = 13,
        data_inicio = '2023-01-11',
        data_fim    = '2027-12-31',
        sincronizado_em = NOW()
")->execute([SOURCE, LEG_ID]);

// ── 4. Prepara statements ─────────────────────────────────────────────────────

$stUpsert = $pdo->prepare("
    INSERT INTO parl_parlamentares
        (source_key, sapl_id, nome_completo, nome_parlamentar, partido_sigla, uf,
         fotografia_url, ativo, sincronizado_em, titular)
    VALUES (?,?,?,?,?,?, ?,1,NOW(),1)
    ON DUPLICATE KEY UPDATE
        nome_completo    = VALUES(nome_completo),
        nome_parlamentar = VALUES(nome_parlamentar),
        partido_sigla    = VALUES(partido_sigla),
        ativo            = 1,
        sincronizado_em  = NOW()
");

$stEmail = $pdo->prepare(
    "UPDATE parl_parlamentares SET email=? WHERE source_key=? AND sapl_id=?"
);

$stFoto = $pdo->prepare(
    "UPDATE parl_parlamentares SET fotografia_url=? WHERE source_key=? AND sapl_id=?"
);

$stPerfil = $pdo->prepare("
    INSERT INTO parl_perfil_detalhe
        (source_key, sapl_id, situacao, biografia, telefone, atualizado_em)
    VALUES (?, ?, 'Deputado Estadual', ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
        situacao      = 'Deputado Estadual',
        biografia     = VALUES(biografia),
        telefone      = VALUES(telefone),
        atualizado_em = NOW()
");

$stMandato = $pdo->prepare("
    INSERT INTO parl_mandatos
        (source_key, parlamentar_id, legislatura_id, titular, votos_recebidos, sincronizado_em)
    VALUES (?, ?, ?, 1, ?, NOW())
    ON DUPLICATE KEY UPDATE
        titular          = 1,
        votos_recebidos  = COALESCE(VALUES(votos_recebidos), votos_recebidos),
        sincronizado_em  = NOW()
");

$stDelFil = $pdo->prepare(
    "DELETE FROM parl_filiacoes WHERE source_key=? AND sapl_id=?"
);

$stFiliacao = $pdo->prepare("
    INSERT INTO parl_filiacoes
        (source_key, sapl_id, partido_sigla, partido_nome, data_filiacao, atual, atualizado_em)
    VALUES (?, ?, ?, '', ?, 1, NOW())
");

// ── 5. Processa cada deputado ─────────────────────────────────────────────────

$fotoOk = $fotoSkip = $semFoto = $bioOk = $semBio = 0;

foreach ($deputies as $dep) {
    $localUrl = '/uploads/parlamentares/' . SOURCE . '/' . $dep['id'] . '.jpg';

    $stUpsert->execute([
        SOURCE, $dep['id'],
        $dep['nome'], $dep['nome'],
        $dep['party'], UF,
        $localUrl,
    ]);

    // ── Perfil (bio, email, telefone, filiação, votos) ─────────────────────
    $votos    = null;
    $bio      = null;
    $email    = null;
    $telefone = null;

    if (!$skipBio) {
        $profileUrl  = BASE_URL . '/Deputados/PerfilDeputado/' . $dep['id'] . '?Legislatura=' . LEG_ID;
        $profileHtml = curlGet($profileUrl, 15);

        if ($profileHtml) {
            // Email e telefone — abaixo de <h2 class="margin_bottom_5">CONTATO</h2>
            if (preg_match('#<h2[^>]*>\s*CONTATO\s*</h2>([\s\S]{1,500}?)(?=<(?:h[1-3]|div)\s)#i', $profileHtml, $cm)) {
                preg_match_all('#<p[^>]*>([^<]+)</p>#i', $cm[1], $cp);
                foreach ($cp[1] as $item) {
                    $item = trim(html_entity_decode($item, ENT_QUOTES, 'UTF-8'));
                    if (!$email && filter_var($item, FILTER_VALIDATE_EMAIL)) {
                        $email = $item;
                    } elseif (!$telefone && preg_match('#[\d\(\)\s\-\+]{5,}#', $item)) {
                        $telefone = $item;
                    }
                }
            }

            // Biografia — somente parágrafos dentro de divAbaBiografia
            if (preg_match('#<div\s+id="divAbaBiografia"[^>]*>([\s\S]*?)</div>\s*<div\s+id="divAbaAtuacao"#i', $profileHtml, $bm)) {
                $paragraphs = [];
                preg_match_all('#<p[^>]*>([\s\S]+?)</p>#i', $bm[1], $pm);
                foreach (($pm[1] ?? []) as $p) {
                    $text = trim(strip_tags(html_entity_decode($p, ENT_QUOTES, 'UTF-8')));
                    $text = preg_replace('/\s+/', ' ', $text);
                    if (strlen($text) > 20) $paragraphs[] = $text;
                }
                if ($paragraphs) $bio = implode("\n\n", $paragraphs);
            }

            // Votos — extraídos do texto da bio: "42.720 eleitores" ou "42.720 votos"
            if ($bio && preg_match('#(\d{1,3}(?:[.,]\d{3})+|\d{4,})\s*(?:eleitores|votos)#iu', $bio, $vm)) {
                $votos = preg_replace('#[.,](?=\d{3})#', '', $vm[1]); // remove separador de milhar
            }

            // Partido — extraído do texto da bio como fallback (ex: "concorrendo pelo PL")
            // Só aplica se o partido da lista não foi capturado
            if (!$dep['party'] && $bio) {
                if (preg_match('#(?:pelo|do partido|filiado ao?)\s+([A-Z]{2,10})(?:\s|,|\.)#u', $bio, $ptm)) {
                    $dep['party'] = trim($ptm[1]);
                    $pdo->prepare("UPDATE parl_parlamentares SET partido_sigla=? WHERE source_key=? AND sapl_id=?")
                        ->execute([$dep['party'], SOURCE, $dep['id']]);
                }
            }

            // Filiação — "Posse na ALERJ: PL - Data DD/MM/YYYY" dentro de divAbaAtuacao
            if (preg_match('#Posse na ALERJ:\s*([A-Z0-9\-]+)\s*-\s*Data\s+(\d{2}/\d{2}/\d{4})#i', $profileHtml, $fm)) {
                $partyPosse  = trim($fm[1]);
                [$d, $m2, $y] = explode('/', $fm[2]);
                $dataPosse   = "{$y}-{$m2}-{$d}";
                $stDelFil->execute([SOURCE, $dep['id']]);
                $stFiliacao->execute([SOURCE, $dep['id'], $partyPosse, $dataPosse]);
            }

            if ($email) $stEmail->execute([$email, SOURCE, $dep['id']]);
        }

        // Persiste perfil (bio + telefone; NULL se não encontrado)
        $stPerfil->execute([SOURCE, $dep['id'], $bio, $telefone]);

        if ($bio) {
            $bioOk++;
            echo "  [ok] {$dep['nome']} [{$dep['id']}]"
                . ($email  ? " <{$email}>"       : '')
                . ($votos  ? " votos={$votos}"   : '')
                . "\n";
        } else {
            $semBio++;
        }

        usleep(250000); // 250ms entre requisições de perfil
    }

    // Mandato 13ª legislatura — inserido depois de extrair votos da bio
    $stMandato->execute([SOURCE, $dep['id'], LEG_ID, $votos]);

    // ── Foto ───────────────────────────────────────────────────────────────
    if ($skipFotos) {
        $fotoOk++;
        continue;
    }

    $localPath = $uploadDir . '/' . $dep['id'] . '.jpg';

    if (!$force && file_exists($localPath) && filesize($localPath) > 5000) {
        $stFoto->execute([$localUrl, SOURCE, $dep['id']]);
        $fotoSkip++;
        continue;
    }

    $rawFoto  = html_entity_decode($dep['foto'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $fotoUrl  = BASE_URL . dirname($rawFoto) . '/' . rawurlencode(basename($rawFoto));
    $fc = curl_init($fotoUrl);
    curl_setopt_array($fc, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; keekconecta/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $imgBody  = curl_exec($fc);
    $fcode    = curl_getinfo($fc, CURLINFO_HTTP_CODE);
    $mime     = curl_getinfo($fc, CURLINFO_CONTENT_TYPE);
    curl_close($fc);

    if ($imgBody && $fcode === 200 && strlen($imgBody) > 5000) {
        // Detecta se veio PNG mesmo com extensão .jpg
        $ext = str_contains($mime ?? '', 'png') ? 'png' : 'jpg';
        $savePath = $uploadDir . '/' . $dep['id'] . '.' . $ext;
        $saveUrl  = '/uploads/parlamentares/' . SOURCE . '/' . $dep['id'] . '.' . $ext;
        file_put_contents($savePath, $imgBody);
        $stFoto->execute([$saveUrl, SOURCE, $dep['id']]);
        $fotoOk++;
        echo "  ✓ {$dep['nome']} [{$dep['id']}]\n";
    } else {
        $semFoto++;
        echo "  - {$dep['nome']} [{$dep['id']}] sem foto (HTTP {$fcode})\n";
    }

    usleep(150000); // 150ms entre downloads de foto
}

// ── 6. Atualiza fonte_sincs e fontes_legislativas ─────────────────────────────

// detalhes_em marcado → fonteOffline() retorna true, proxy nunca tenta SAPL para alrj
$pdo->prepare("
    INSERT INTO fonte_sincs (source_key, status, iniciado_em, concluido_em, total_parl, detalhes_em)
    VALUES (?, 'ok', NOW(), NOW(), ?, NOW())
    ON DUPLICATE KEY UPDATE status='ok', concluido_em=NOW(), total_parl=?, detalhes_em=NOW()
")->execute([SOURCE, $total, $total]);

$pdo->prepare("
    INSERT INTO fontes_legislativas (source_key, label, url)
    VALUES ('alrj', 'ALERJ', 'https://www.alerj.rj.gov.br')
    ON DUPLICATE KEY UPDATE url='https://www.alerj.rj.gov.br'
")->execute();

// ── 7. Relatório ──────────────────────────────────────────────────────────────

$dur = round(microtime(true) - $inicio);
echo "\n[alerj] Concluído em {$dur}s\n";
echo "[alerj] Parlamentares: {$total} | Mandatos: {$total} (13ª legislatura)\n";
if (!$skipFotos) {
    echo "[alerj] Fotos: {$fotoOk} baixadas, {$fotoSkip} já existiam, {$semFoto} falhas\n";
}
if (!$skipBio) {
    echo "[alerj] Perfis (bio+email+telefone+filiação): {$bioOk} ok, {$semBio} sem bio\n";
}
