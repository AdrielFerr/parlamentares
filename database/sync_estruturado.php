<?php
/**
 * sync_estruturado.php
 *
 * Lê o sapl_cache (populado por sync_detalhes.php) e extrai os dados para
 * tabelas relacionais estruturadas, prontas para consulta por dashboards e
 * agentes de IA sem precisar parsear JSON:
 *
 *   parl_perfil_detalhe  — situação, biografia, nascimento, escolaridade...
 *   parl_filiacoes       — histórico de filiações partidárias
 *   parl_comissoes       — participação em comissões
 *   parl_materias        — matérias/proposições de autoria
 *   parl_normas          — normas jurídicas de autoria (SAPL)
 *   parl_relatorias      — relatorias designadas
 *   parl_frentes         — participação em frentes parlamentares
 *
 * Pré-requisito: sync_detalhes.php já rodado para a fonte.
 *
 * Uso:
 *   php database/sync_estruturado.php                        — todas as fontes com status 'ok'
 *   php database/sync_estruturado.php camara_federal alpb senado
 *   php database/sync_estruturado.php alpb --force           — recria mesmo se recente
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

$pdo  = Database::connect();
$args = array_slice($argv, 1);
$force = in_array('--force', $args);
$args  = array_values(array_filter($args, fn($a) => $a !== '--force'));

$todasFontes = array_keys(SOURCES);
if ($args) {
    $fontesAlvo = array_values(array_filter($todasFontes, fn($k) => in_array($k, $args)));
} else {
    $rows = $pdo->query("SELECT source_key FROM fonte_sincs WHERE status='ok' ORDER BY source_key")->fetchAll();
    $fontesAlvo = array_column($rows, 'source_key');
}

if (empty($fontesAlvo)) {
    echo "Nenhuma fonte disponível. Rode sync_detalhes.php primeiro ou passe as fontes como argumento.\n";
    exit(1);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function toDate(?string $s): ?string {
    if (!$s || $s === '' || $s === '0000-00-00') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) return substr($s, 0, 10);
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    return null;
}

// Busca todos os results de um grupo de páginas no sapl_cache (LIKE prefix)
function getAllResults(PDO $pdo, string $source, string $keyPrefix): array {
    $stmt = $pdo->prepare(
        "SELECT data FROM sapl_cache
         WHERE source=? AND cache_key LIKE ? AND expires_at > NOW()
         ORDER BY cache_key"
    );
    $stmt->execute([$source, $keyPrefix . '%']);
    $all = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $data = json_decode($raw, true) ?: [];
        foreach ($data['results'] ?? [] as $r) {
            $all[] = $r;
        }
    }
    return $all;
}

// Busca a primeira página (para endpoints de página única)
function getFirstResults(PDO $pdo, string $source, string $cacheKey): array {
    $stmt = $pdo->prepare(
        "SELECT data FROM sapl_cache WHERE source=? AND cache_key=? AND expires_at > NOW()"
    );
    $stmt->execute([$source, $cacheKey]);
    $raw = $stmt->fetchColumn();
    if (!$raw) return [];
    $data = json_decode($raw, true) ?: [];
    return $data['results'] ?? [];
}

// Extrai o autor_id SAPL:
// 1ª tentativa: cache bulk /base/autor/ por object_id (= parlamentar sapl_id) — mais confiável
// 2ª tentativa: cache por nome (/base/autor/&nome=X) — fallback para fontes sem bulk
function getAutorId(PDO $pdo, string $source, int $parlamentarSaplId, string $nome = '', ?string $nomeCompleto = null): ?int {
    // Bulk cache lookup por object_id
    $stmt = $pdo->prepare(
        "SELECT data FROM sapl_cache
         WHERE source=? AND cache_key LIKE '/base/autor/&page=%' AND expires_at > NOW()
         ORDER BY cache_key"
    );
    $stmt->execute([$source]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $data = json_decode($raw, true) ?: [];
        foreach ($data['results'] ?? [] as $r) {
            // content_type=2 (tipo=2) identifica parlamentar — evita colisão com frentes/comissoes
            $isParl = !isset($r['content_type']) || (int)($r['content_type']) === 2;
            if ($isParl && (int)($r['object_id'] ?? -1) === $parlamentarSaplId) {
                return (int)($r['id'] ?? 0) ?: null;
            }
        }
    }

    // Fallback: cache por nome (gerado pelo sync_detalhes anterior ou por busca direta)
    foreach (array_unique(array_filter([$nome, $nomeCompleto])) as $n) {
        $cacheKey = '/base/autor/&' . http_build_query(['nome' => $n]);
        $stmt2 = $pdo->prepare(
            "SELECT data FROM sapl_cache WHERE source=? AND cache_key=? AND expires_at > NOW()"
        );
        $stmt2->execute([$source, $cacheKey]);
        $raw = $stmt2->fetchColumn();
        if ($raw) {
            $data = json_decode($raw, true) ?: [];
            $id   = (int)($data['results'][0]['id'] ?? 0);
            if ($id) return $id;
        }
    }
    return null;
}

// ── Loop principal ─────────────────────────────────────────────────────────────

$inicio = microtime(true);
echo "[estruturado] Fontes: " . implode(', ', $fontesAlvo) . ($force ? ' [--force]' : '') . "\n\n";

foreach ($fontesAlvo as $source) {
    $isSapl  = !in_array($source, ['camara_federal', 'senado']);
    $fInicio = microtime(true);

    echo "┌─ {$source} " . str_repeat('─', max(0, 50 - strlen($source))) . "\n";

    $rows = $pdo->prepare(
        "SELECT sapl_id, nome_parlamentar, nome_completo
         FROM parl_parlamentares WHERE source_key = ? ORDER BY sapl_id"
    );
    $rows->execute([$source]);
    $parlamentares = $rows->fetchAll();
    $total  = count($parlamentares);
    $done   = 0;
    $erros  = 0;

    echo "│  {$total} parlamentares\n";

    // Carrega mapa comissao_id → {sigla, nome} do sapl_cache
    $comissaoDetalheMap  = []; // comissao_id  → {sigla, nome}
    $composicaoDetalheMap = []; // composicao_id → comissao_id
    $stmt = $pdo->prepare("SELECT cache_key, data FROM sapl_cache WHERE source=? AND cache_key LIKE '/comissoes/comissao/%' AND expires_at > NOW()");
    $stmt->execute([$source]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $c = json_decode($row['data'], true) ?: [];
        $id = (int)($c['id'] ?? 0);
        if ($id) $comissaoDetalheMap[$id] = ['sigla' => $c['sigla'] ?? '', 'nome' => $c['nome'] ?? $c['__str__'] ?? ''];
    }

    // Mapa composicao → comissao: lê via API para SAPL (mais confiável que cache individual)
    if ($isSapl) {
        $pg = 1;
        do {
            $raw  = SaplApi::getRaw('/comissoes/composicao/', $source, ['page' => $pg]);
            $data = json_decode($raw, true) ?: [];
            foreach ($data['results'] ?? [] as $comp) {
                $cId   = (int)($comp['id'] ?? 0);
                $comId = is_array($comp['comissao'] ?? null)
                           ? (int)($comp['comissao']['id'] ?? 0)
                           : (int)($comp['comissao'] ?? 0);
                if ($cId && $comId) {
                    $composicaoDetalheMap[$cId] = $comId;
                    SaplCache::set($source, "/comissoes/composicao/{$cId}/", json_encode($comp), 720);
                }
            }
            $totalPg = (int)($data['pagination']['total_pages'] ?? 1);
            $pg++;
        } while ($pg <= $totalPg && $pg <= 200);
    }

    // Carregar lista base de parlamentares como fallback de perfil
    // (fontes sem endpoint /perfil/ individual, ex: ALPB, Senado)
    $parlBaseMap = [];
    foreach (getAllResults($pdo, $source, '/parlamentares/parlamentar') as $lr) {
        $parlBaseMap[(int)($lr['id'] ?? 0)] = $lr;
    }

    // Prepared statements reutilizáveis
    $stDelFil  = $pdo->prepare("DELETE FROM parl_filiacoes       WHERE source_key=? AND sapl_id=?");
    $stDelCom  = $pdo->prepare("DELETE FROM parl_comissoes       WHERE source_key=? AND sapl_id=?");
    $stDelMat  = $pdo->prepare("DELETE FROM parl_materias        WHERE source_key=? AND sapl_id=?");
    $stDelNor  = $pdo->prepare("DELETE FROM parl_normas          WHERE source_key=? AND sapl_id=?");
    $stDelRel  = $pdo->prepare("DELETE FROM parl_relatorias      WHERE source_key=? AND sapl_id=?");
    $stDelFre  = $pdo->prepare("DELETE FROM parl_frentes         WHERE source_key=? AND sapl_id=?");

    $stFil = $pdo->prepare(
        "INSERT IGNORE INTO parl_filiacoes (source_key,sapl_id,partido_sigla,partido_nome,data_filiacao,data_desfiliacao,atual)
         VALUES (?,?,?,?,?,?,?)"
    );
    $stFilAtual = $pdo->prepare(
        "UPDATE parl_filiacoes SET atual=1
         WHERE source_key=? AND sapl_id=? AND partido_sigla=? AND (data_filiacao<=>?)"
    );
    $stCom = $pdo->prepare(
        "INSERT INTO parl_comissoes (source_key,sapl_id,comissao_str,comissao_id,data_inicio,data_fim,titular)
         VALUES (?,?,?,?,?,?,?)"
    );
    $stMat = $pdo->prepare(
        "INSERT INTO parl_materias (source_key,sapl_id,materia_id,tipo_sigla,numero,ano,ementa,data_apresentacao,situacao,descricao,primeiro_autor)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stNor = $pdo->prepare(
        "INSERT INTO parl_normas (source_key,sapl_id,norma_id,tipo_sigla,numero,ano,ementa,data_norma,texto_integral,descricao)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    $stRel = $pdo->prepare(
        "INSERT IGNORE INTO parl_relatorias (source_key,sapl_id,materia_id,materia_str,comissao_str,data_designacao,data_destituicao)
         VALUES (?,?,?,?,?,?,?)"
    );
    $stFre = $pdo->prepare(
        "INSERT INTO parl_frentes (source_key,sapl_id,frente_id,frente_nome,cargo,ativa)
         VALUES (?,?,?,?,?,?)"
    );
    $stPerf = $pdo->prepare(
        "INSERT INTO parl_perfil_detalhe
            (source_key,sapl_id,situacao,biografia,data_nascimento,municipio_nascimento,uf_nascimento,escolaridade,profissao,homepage,gabinete,telefone)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            situacao=VALUES(situacao), biografia=VALUES(biografia),
            data_nascimento=VALUES(data_nascimento), municipio_nascimento=VALUES(municipio_nascimento),
            uf_nascimento=VALUES(uf_nascimento), escolaridade=VALUES(escolaridade),
            profissao=VALUES(profissao), homepage=VALUES(homepage),
            gabinete=VALUES(gabinete), telefone=VALUES(telefone),
            atualizado_em=NOW()"
    );

    foreach ($parlamentares as $parl) {
        $done++;
        $id    = (int)$parl['sapl_id'];
        $nome  = $parl['nome_parlamentar'] ?: $parl['nome_completo'];
        $nomeC = $parl['nome_completo'];

        if ($done === 1 || $done % 50 === 0 || $done === $total) {
            $pct = round($done / $total * 100);
            echo "│  [{$done}/{$total} {$pct}%] {$nome}\n";
            flush();
        }

        try {
            // ── Perfil detalhe ──────────────────────────────────────────────
            $perfSaved = false;
            // Senado: perfil não é cacheado por sync_detalhes — chama API diretamente
            if ($source === 'senado') {
                $rawPerf = SaplApi::getRaw("/parlamentares/perfil/?parlamentar={$id}", $source, []);
                $datPerf = json_decode($rawPerf, true) ?? [];
                if (!empty($datPerf['results'][0])) {
                    $p = $datPerf['results'][0];
                    $stPerf->execute([
                        $source, $id,
                        $p['condicaoEleitoral'] ?? 'Titular',
                        null,
                        toDate($p['dataNascimento']      ?? null),
                        $p['municipioNascimento']        ?? null,
                        $p['ufNascimento']               ?? null,
                        $p['escolaridade']               ?? null,
                        $p['profissao']                  ?? null,
                        $p['sitePessoal']                ?? null,
                        null,
                        $p['telefone']                   ?? null,
                    ]);
                    $perfSaved = true;
                }
            }
            $perfil = $perfSaved ? [] : getFirstResults($pdo, $source, "/parlamentares/perfil/?parlamentar={$id}&page=1");
            if (!empty($perfil)) {
                $p   = $perfil[0];
                // Câmara: { dataNascimento, municipioNascimento, ufNascimento, escolaridade, sitePessoal, condicaoEleitoral }
                // SAPL generic: { parlamentar: {...}, ...campos variados }
                $parl_data = is_array($p['parlamentar'] ?? null) ? $p['parlamentar'] : [];
                $stPerf->execute([
                    $source, $id,
                    $p['condicaoEleitoral']   ?? $p['situacao']          ?? ($parl_data['ativo'] ? 'Titular' : ''),
                    $p['biografia']           ?? $parl_data['biografia'] ?? null,
                    toDate($p['dataNascimento']        ?? $parl_data['data_nascimento']       ?? null),
                    $p['municipioNascimento'] ?? $parl_data['municipio_nascimento']           ?? null,
                    $p['ufNascimento']        ?? $parl_data['uf_nascimento']                  ?? null,
                    $p['escolaridade']        ?? $parl_data['escolaridade']                   ?? null,
                    $p['profissao']           ?? $parl_data['profissao']                      ?? null,
                    $p['sitePessoal']         ?? $parl_data['endereco_web']                   ?? null,
                    $p['gabinete']            ?? $parl_data['numero_gab_parlamentar']         ?? null,
                    $p['telefone']            ?? $parl_data['telefone']                       ?? null,
                ]);
            } elseif (!$perfSaved && ($lr = ($parlBaseMap[$id] ?? null))) {
                // Fallback: extrair perfil da lista base (fontes sem endpoint /perfil/ individual)
                $bio = ($lr['biografia'] ?? null);
                if ($bio === '0' || $bio === '') $bio = null;
                $stPerf->execute([
                    $source, $id,
                    ($lr['ativo'] ?? true) ? 'Titular' : 'Inativo',
                    $bio,
                    null, null, null,
                    $lr['nivel_instrucao'] ?? null,
                    $lr['profissao']       ?? null,
                    $lr['endereco_web']    ?? null,
                    $lr['numero_gab_parlamentar'] ?? null,
                    $lr['telefone']        ?? null,
                ]);
            }

            // ── Filiações ───────────────────────────────────────────────────
            $stDelFil->execute([$source, $id]);
            $filiacoes  = getAllResults($pdo, $source, "/parlamentares/filiacao/?parlamentar={$id}&");
            $latestFil  = null;
            foreach ($filiacoes as $f) {
                // SAPL: partido = {sigla, nome}  |  Câmara/Senado: partido = 'SIGLA'  |  Alguns SAPL: partido = ID numérico
                $partido = $f['partido'] ?? '';
                if (is_array($partido)) {
                    $sigla = $partido['sigla'] ?? '';
                    $pNome = $partido['nome']  ?? '';
                } elseif (is_int($partido) || ctype_digit((string)$partido)) {
                    // ID numérico: extrai sigla do __str__ (formato: "NomeParlamentar - SIGLA - NomePartido")
                    $parts = explode(' - ', $f['__str__'] ?? '');
                    $n     = count($parts);
                    $sigla = $n >= 2 ? trim($parts[$n - 2]) : '';
                    // Se penúltimo é muito longo ou igual ao nome do partido, tenta o primeiro token de 2-15 chars
                    if (strlen($sigla) > 15 || !preg_match('/^[A-Za-zÀ-ÿ\/]+$/', $sigla)) {
                        foreach (array_slice($parts, 1, -1) as $p) {
                            $p = trim($p);
                            if (strlen($p) >= 2 && strlen($p) <= 15 && preg_match('/^[A-ZÁÉÍÓÚÀÂÊÔÃ\/]+$/', $p)) {
                                $sigla = $p;
                                break;
                            }
                        }
                    }
                    $pNome = $n >= 1 ? trim($parts[$n - 1]) : '';
                } else {
                    $sigla = (string)$partido;
                    $pNome = '';
                }
                $dataF = toDate($f['data'] ?? null);
                $dataD = toDate($f['data_desfiliacao'] ?? null);
                if (!$sigla) continue;
                $stFil->execute([$source, $id, $sigla, $pNome, $dataF, $dataD, 0]);
                if (!$latestFil || ($dataF && (!$latestFil['data'] || $dataF > $latestFil['data']))) {
                    $latestFil = ['sigla' => $sigla, 'data' => $dataF];
                }
            }
            if ($latestFil) {
                $stFilAtual->execute([$source, $id, $latestFil['sigla'], $latestFil['data']]);
            }

            // ── Comissões ───────────────────────────────────────────────────
            $stDelCom->execute([$source, $id]);
            $comissoes = getAllResults($pdo, $source, "/comissoes/participacao/?parlamentar={$id}&");
            foreach ($comissoes as $c) {
                $str        = $c['__str__'] ?? '';
                $composicao = (int)($c['composicao'] ?? 0);
                $comissaoId = null;

                // Resolve nome real da comissão via composicao_id → comissao
                if ($composicao && isset($composicaoDetalheMap[$composicao])) {
                    $comId = $composicaoDetalheMap[$composicao];
                    $comissaoId = $comId;
                    if (isset($comissaoDetalheMap[$comId])) {
                        $com      = $comissaoDetalheMap[$comId];
                        $cargo    = explode(' : ', $str)[0] ?? '';
                        $nomeFull = trim(($com['sigla'] ? $com['sigla'] . ' — ' : '') . $com['nome']);
                        $str      = $cargo ? "{$cargo} : {$nomeFull}" : $nomeFull;
                    }
                } elseif (!$str) {
                    // Fallback: campos estruturados (Câmara/outros)
                    $comp   = $c['composicao']   ?? $c;
                    $nomC   = $comp['comissao']['nome']  ?? '';
                    $siglaC = $comp['comissao']['sigla'] ?? '';
                    $cargo  = $comp['cargo']['descricao'] ?? ($c['cargo']['descricao'] ?? '');
                    $str    = trim(($siglaC ? $siglaC . ' — ' : '') . $nomC . ($cargo ? ' - ' . $cargo : ''));
                }

                $stCom->execute([
                    $source, $id,
                    $str,
                    $comissaoId,
                    toDate($c['data_inicio_participacao'] ?? $c['data_designacao']   ?? null),
                    toDate($c['data_fim_participacao']    ?? $c['data_desligamento'] ?? null),
                    (int)(($c['titular'] ?? false) || strtolower($c['cargo']['descricao'] ?? '') === 'titular'),
                ]);
            }

            // ── Matérias e Normas — precisam do autor_id ────────────────────
            $autorId = $isSapl ? getAutorId($pdo, $source, $id, $nome, $nomeC) : $id;

            if ($autorId) {
                // Matérias
                $stDelMat->execute([$source, $id]);
                $materias = getAllResults($pdo, $source, "/materia/autoria/?autor={$autorId}&");
                foreach ($materias as $m) {
                    // SAPL: m.materia = {tipo:{sigla,descricao}, numero, ano, ementa, data_apresentacao}
                    // Câmara/Senado: __str__, materia (id), primeiro_autor, ano
                    $mat    = is_array($m['materia'] ?? null) ? $m['materia'] : [];
                    $mId    = $mat ? (int)($mat['id'] ?? 0) : ((int)($m['materia'] ?? 0) ?: null);
                    $tipo   = $mat['tipo']['sigla'] ?? ($mat['tipo'] ?? '');
                    $num    = $mat['numero']         ?? '';
                    $ano    = (int)($mat['ano']      ?? $m['ano']  ?? 0) ?: null;
                    $ementa = $mat['ementa']         ?? null;
                    $dataAp = toDate($mat['data_apresentacao'] ?? null);
                    $sit    = $mat['situacao']        ?? '';
                    if (is_array($sit)) $sit = $sit['descricao'] ?? '';
                    $desc   = $m['__str__'] ?? trim("{$tipo} nº {$num}/{$ano}");
                    $prim   = (int)($m['primeiro_autor'] ?? true);
                    $stMat->execute([$source, $id, $mId, $tipo, $num, $ano, $ementa, $dataAp, $sit, $desc, $prim]);
                }

                // Normas
                $stDelNor->execute([$source, $id]);
                if ($isSapl) {
                    $normas = getAllResults($pdo, $source, "/norma/autorianorma/?autor={$autorId}&");
                    foreach ($normas as $n) {
                        $nor   = is_array($n['norma'] ?? null) ? $n['norma'] : $n;
                        $nId   = (int)($nor['id']   ?? $n['id']  ?? 0) ?: null;
                        $tipo  = $nor['tipo']['sigla'] ?? ($nor['tipo'] ?? '');
                        $num   = $nor['numero']  ?? '';
                        $ano   = (int)($nor['ano'] ?? 0) ?: null;
                        $enem  = $nor['ementa']  ?? null;
                        $datN  = toDate($nor['data'] ?? null);
                        $desc  = $n['__str__'] ?? trim("{$tipo} nº {$num}/{$ano}");
                        $url   = $nor['texto_integral'] ?? null;
                        $stNor->execute([$source, $id, $nId, $tipo, $num, $ano, $enem, $datN, $url, $desc]);
                    }
                } elseif ($source === 'senado') {
                    // Senado: matérias encerradas do tipo legislativo (pesquisa/lista?codigoAutor&tramitando=N)
                    $raw  = SaplApi::getRaw("/norma/autorianorma/?autor={$id}", $source, []);
                    $data = json_decode($raw, true) ?? [];
                    $list = $data['results'] ?? [];
                    foreach ($list as $item) {
                        $nId  = (int)($item['id'] ?? 0) ?: null;
                        $tipo = $item['tipo'] ?? '';
                        $num  = $item['numero'] ?? '';
                        $ano  = (int)($item['ano'] ?? 0) ?: null;
                        $enem = $item['ementa'] ?? null;
                        $datN = toDate($item['data'] ?? null);
                        $url  = $item['texto_integral'] ?? null;
                        $desc = $item['__str__'] ?? trim("{$tipo} nº {$num}/{$ano}");
                        $stNor->execute([$source, $id, $nId, $tipo, $num, $ano, $enem, $datN, $url, $desc]);
                    }
                } elseif ($source === 'camara_federal') {
                    // Câmara: ler da cache de autorias e filtrar por tipo legislativo.
                    // tipo_sigla está no campo tipo_sigla (novos registros) ou parseado do __str__.
                    $siglasProposta = ['PL','PLC','PLS','PLN','PLP','PEC','PDL','PRS','MPV','PDS'];
                    $materiasCam = getAllResults($pdo, $source, "/materia/autoria/?autor={$autorId}&");
                    foreach ($materiasCam as $m) {
                        $tipo = $m['tipo_sigla'] ?? explode(' ', $m['__str__'] ?? '')[0];
                        if (!$tipo || !in_array($tipo, $siglasProposta)) continue;
                        $nId  = (int)($m['materia'] ?? 0) ?: null;
                        $num  = $m['numero'] ?? '';
                        $ano  = (int)($m['ano'] ?? 0) ?: null;
                        $enem = $m['ementa'] ?? null;
                        $desc = $m['__str__'] ?? trim("{$tipo} nº {$num}/{$ano}");
                        $stNor->execute([$source, $id, $nId, $tipo, $num, $ano, $enem, null, null, $desc]);
                    }
                }
            }

            // ── Relatorias ──────────────────────────────────────────────────
            $stDelRel->execute([$source, $id]);
            $relatorias = getAllResults($pdo, $source, "/materia/relatoria/?parlamentar={$id}&");
            foreach ($relatorias as $r) {
                $mId  = (int)($r['materia'] ?? 0) ?: null;
                $str  = $r['__str__'] ?? '';
                $com  = is_array($r['comissao'] ?? null) ? ($r['comissao']['__str__'] ?? '') : '';
                $stRel->execute([
                    $source, $id,
                    $mId, $str, $com,
                    toDate($r['data_designacao_relator']  ?? null),
                    toDate($r['data_destituicao_relator'] ?? null),
                ]);
            }

            // ── Frentes ─────────────────────────────────────────────────────
            $stDelFre->execute([$source, $id]);
            $frentes = getAllResults($pdo, $source, "/parlamentares/frenteparlamentar/?parlamentar={$id}&");
            foreach ($frentes as $f) {
                $fId    = (int)($f['frente'] ?? 0) ?: null;
                $fNome  = $f['__str__'] ?? ($f['frente']['nome'] ?? '');
                $cargo  = $f['cargo'] ?? '';
                $stFre->execute([$source, $id, $fId, $fNome, $cargo, 1]);
            }

        } catch (Throwable $e) {
            $erros++;
            echo "│  ERRO [{$id}] {$e->getMessage()}\n";
        }
    }

    $dur = round(microtime(true) - $fInicio);
    echo "└─ {$source}: {$total} parlamentares, {$erros} erros — {$dur}s\n\n";
}

echo "[estruturado] Concluído em " . round(microtime(true) - $inicio) . "s.\n";
