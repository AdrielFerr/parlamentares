<?php
class ApiController extends Controller {
    public function proxy(): void {
        $this->requireAuth();
        session_write_close(); // libera o lock de sessão para permitir requisições paralelas

        $source = $_GET['source'] ?? DEFAULT_SOURCE;
        $path   = $_GET['path']   ?? '';

        if (!$path || !preg_match('#^/[a-zA-Z0-9/_\-\.?=&%+]+$#', $path)) {
            $this->json(['error' => 'Caminho inválido.'], 400);
        }

        // Strip leading /api if present (SaplApi adds it)
        if (str_starts_with($path, '/api/')) {
            $path = substr($path, 4);
        }

        // Forward extra params (page, search, etc.) to the SAPL API
        $reserved = ['source', 'path', '1'];
        $extra    = [];
        foreach ($_GET as $k => $v) {
            if (!in_array($k, $reserved)) {
                $extra[$k] = $v;
            }
        }

        // Intercepts: serve endpoints direto do banco quando os dados estão disponíveis
        $dbResult = $this->proxyFromDb($source, $path, $extra);
        if ($dbResult !== null) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Cache: DB');
            echo json_encode($dbResult);
            exit;
        }

        // Cache key normalizado: path + params ordenados
        ksort($extra);
        $cacheKey = $path . ($extra ? '&' . http_build_query($extra) : '');

        $cached = SaplCache::get($source, $cacheKey);
        if ($cached !== null) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Cache: HIT');
            echo $cached;
            exit;
        }

        $body = SaplApi::getRaw($path, $source, $extra);

        // Só armazena respostas válidas
        if ($body !== '{}' && !str_contains($body, '__rate_limited')) {
            SaplCache::set($source, $cacheKey, $body, SaplCache::ttlFor($path));
        }

        header('Content-Type: application/json; charset=utf-8');
        header('X-Cache: MISS');
        echo $body;
        exit;
    }

    private function proxyFromDb(string $source, string $path, array $extra): ?array
    {
        // Extrai parâmetros que podem vir no path ou como extra params
        $parlId = (int)($extra['parlamentar'] ?? 0);
        if (!$parlId) { preg_match('/[?&]parlamentar=(\d+)/', $path, $m); $parlId = (int)($m[1] ?? 0); }

        $legId = (int)($extra['legislatura'] ?? 0);
        if (!$legId) { preg_match('/[?&]legislatura=(\d+)/', $path, $m); $legId = (int)($m[1] ?? 0); }

        $autorId = (int)($extra['autor'] ?? 0);
        if (!$autorId) { preg_match('/[?&]autor=(\d+)/', $path, $m); $autorId = (int)($m[1] ?? 0); }

        $db = Database::connect();

        $wrap = fn(array $rows): array => [
            'results'    => $rows,
            'pagination' => ['total_pages' => 1, 'total_entries' => count($rows), 'links' => new stdClass()],
        ];

        // /base/autor/ — retorna o sapl_id do parlamentar como id de autor para fontes SAPL
        // Isso garante que autoria e normas busquem pelo mesmo ID armazenado em parl_materias.sapl_id
        // /parlamentares/perfil/ — serve do parl_perfil_detalhe (nunca cai na API real)
        if (str_contains($path, '/parlamentares/perfil') && $parlId) {
            $st = $db->prepare(
                "SELECT situacao, biografia, data_nascimento, municipio_nascimento,
                        uf_nascimento, escolaridade, profissao, homepage, gabinete, telefone
                 FROM parl_perfil_detalhe WHERE source_key = ? AND sapl_id = ?"
            );
            $st->execute([$source, $parlId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            return $wrap($r ? [[
                'condicaoEleitoral'   => $r['situacao'],
                'biografia'           => $r['biografia'],
                'dataNascimento'      => $r['data_nascimento'],
                'municipioNascimento' => $r['municipio_nascimento'],
                'ufNascimento'        => $r['uf_nascimento'],
                'escolaridade'        => $r['escolaridade'],
                'profissao'           => $r['profissao'],
                'sitePessoal'         => $r['homepage'],
                'gabinete'            => $r['gabinete'],
                'telefone'            => $r['telefone'],
            ]] : []);
        }

        // /base/autor/ — retorna o sapl_id do parlamentar como id de autor para fontes SAPL
        if (str_contains($path, '/base/autor/')) {
            preg_match('/[?&]nome=([^&]+)/', $path, $m);
            $nome = urldecode($m[1] ?? '') ?: ($extra['nome'] ?? '');
            if ($nome) {
                $st = $db->prepare(
                    "SELECT sapl_id, nome_parlamentar, nome_completo
                     FROM parl_parlamentares
                     WHERE source_key = ?
                       AND (nome_parlamentar = ? OR nome_completo = ?)
                     LIMIT 1"
                );
                $st->execute([$source, $nome, $nome]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    return $wrap([['id' => (int)$r['sapl_id'], '__str__' => $r['nome_parlamentar'] ?: $r['nome_completo']]]);
                }
            }
            return $wrap([]); // não encontrado no banco — sem fallback para API
        }

        // Endpoints conhecidos: sempre retornam do banco, nunca caem na API
        if (str_contains($path, '/parlamentares/mandato') && $legId) {
            $st = $db->prepare(
                "SELECT p.sapl_id AS parlamentar, m.titular, m.votos_recebidos, m.coligacao, l.sapl_id AS legislatura
                 FROM parl_mandatos m
                 JOIN parl_parlamentares p ON p.sapl_id = m.parlamentar_id AND p.source_key = m.source_key
                 JOIN parl_legislaturas l  ON l.sapl_id = m.legislatura_id AND l.source_key = m.source_key
                 WHERE m.source_key = ? AND l.sapl_id = ?"
            );
            $st->execute([$source, $legId]);
            return $wrap(array_map(fn($r) => [
                'parlamentar'     => (int)$r['parlamentar'],
                'titular'         => (bool)$r['titular'],
                'legislatura'     => (int)$r['legislatura'],
                'votos_recebidos' => $r['votos_recebidos'],
                'coligacao'       => $r['coligacao'],
            ], $st->fetchAll(PDO::FETCH_ASSOC)));
        }

        if (str_contains($path, '/parlamentares/mandato') && $parlId) {
            $st = $db->prepare(
                "SELECT p.sapl_id AS parlamentar, m.titular, m.votos_recebidos, m.coligacao, l.sapl_id AS legislatura
                 FROM parl_mandatos m
                 JOIN parl_parlamentares p ON p.sapl_id = m.parlamentar_id AND p.source_key = m.source_key
                 JOIN parl_legislaturas l  ON l.sapl_id = m.legislatura_id AND l.source_key = m.source_key
                 WHERE m.source_key = ? AND p.sapl_id = ?
                 ORDER BY l.numero DESC"
            );
            $st->execute([$source, $parlId]);
            return $wrap(array_map(fn($r) => [
                'parlamentar'     => (int)$r['parlamentar'],
                'titular'         => (bool)$r['titular'],
                'legislatura'     => (int)$r['legislatura'],
                'votos_recebidos' => $r['votos_recebidos'],
                'coligacao'       => $r['coligacao'],
            ], $st->fetchAll(PDO::FETCH_ASSOC)));
        }

        if (str_contains($path, '/parlamentares/filiacao') && $parlId) {
            $st = $db->prepare(
                "SELECT COALESCE(pt.sigla, pf.partido_sigla) AS sigla_real,
                        pf.data_filiacao, pf.data_desfiliacao
                 FROM parl_filiacoes pf
                 LEFT JOIN parl_partidos pt
                   ON pt.source_key = pf.source_key AND pt.sapl_id = pf.partido_sigla
                 WHERE pf.source_key = ? AND pf.sapl_id = ?
                 ORDER BY pf.data_filiacao DESC"
            );
            $st->execute([$source, $parlId]);
            return $wrap(array_map(fn($r) => [
                '__str__'          => $r['sigla_real'],
                'partido'          => $r['sigla_real'],
                'data'             => $r['data_filiacao'],
                'data_desfiliacao' => $r['data_desfiliacao'],
            ], $st->fetchAll(PDO::FETCH_ASSOC)));
        }

        if (str_contains($path, '/comissoes/participacao') && $parlId) {
            $st = $db->prepare(
                "SELECT comissao_str, data_inicio, data_fim, titular, comissao_id
                 FROM parl_comissoes WHERE source_key = ? AND sapl_id = ?
                 ORDER BY data_inicio DESC"
            );
            $st->execute([$source, $parlId]);
            return $wrap(array_map(fn($r) => [
                '__str__'           => $r['comissao_str'],
                'data_designacao'   => $r['data_inicio'],
                'data_desligamento' => $r['data_fim'],
                'titular'           => (bool)$r['titular'],
                'comissao_id'       => $r['comissao_id'] ? (int)$r['comissao_id'] : null,
            ], $st->fetchAll(PDO::FETCH_ASSOC)));
        }

        // Membros atuais de uma comissão (agrupa por parlamentar, pega cargo mais recente)
        if (preg_match('#/comissoes/membros/(\d+)/#', $path, $m)) {
            $comId = (int)$m[1];
            $st = $db->prepare(
                "SELECT pp.nome_parlamentar, pp.sapl_id,
                        pc.comissao_str, pc.data_inicio, pc.data_fim, pc.titular
                 FROM parl_comissoes pc
                 JOIN parl_parlamentares pp ON pp.source_key=pc.source_key AND pp.sapl_id=pc.sapl_id
                 WHERE pc.source_key=? AND pc.comissao_id=?
                 ORDER BY pc.data_inicio DESC"
            );
            $st->execute([$source, $comId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            // Deduplica: mantém entrada mais recente por parlamentar
            $seen = [];
            $membros = [];
            foreach ($rows as $r) {
                $key = $r['sapl_id'];
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $cargo = explode(' : ', $r['comissao_str'])[0] ?? '';
                $membros[] = [
                    'sapl_id'        => (int)$r['sapl_id'],
                    'nome'           => $r['nome_parlamentar'],
                    'cargo'          => $cargo,
                    'titular'        => (bool)$r['titular'],
                    'data_inicio'    => $r['data_inicio'],
                    'data_fim'       => $r['data_fim'],
                ];
            }
            usort($membros, fn($a,$b) => ($b['titular'] <=> $a['titular']) ?: strcmp($a['cargo'],$b['cargo']) ?: strcmp($a['nome'],$b['nome']));
            return $wrap($membros);
        }

        // Detalhe de comissão por ID — serve do sapl_cache
        if (preg_match('#/comissoes/comissao/(\d+)/#', $path, $m)) {
            $comId = (int)$m[1];
            $st = $db->prepare(
                "SELECT data FROM sapl_cache WHERE source = ? AND cache_key = ? AND expires_at > NOW()"
            );
            $st->execute([$source, "/comissoes/comissao/{$comId}/"]);
            $raw = $st->fetchColumn();
            if ($raw) return json_decode($raw, true);
            return ['id' => null, '__str__' => 'Comissão não encontrada'];
        }

        // Capability check sem parlamentar — informa se a fonte tem dados de relatoria
        if (str_contains($path, '/materia/relatoria') && !$parlId && $source !== 'senado') {
            $stCap = $db->prepare("SELECT 1 FROM parl_relatorias WHERE source_key=? LIMIT 1");
            $stCap->execute([$source]);
            if ($stCap->fetchColumn()) {
                return $wrap([['__str__' => 'cap-check', 'materia' => null, 'comissao' => null,
                               'data_designacao_relator' => null, 'data_destituicao_relator' => null]]);
            }
            return null;
        }

        if (str_contains($path, '/materia/relatoria') && $parlId) {
            // Senado usa API live; Câmara Federal e SAPL servem do banco
            if ($source === 'senado') return null;
            $st = $db->prepare(
                "SELECT materia_id, materia_str, comissao_str, data_designacao, data_destituicao
                 FROM parl_relatorias WHERE source_key = ? AND sapl_id = ?
                 ORDER BY data_designacao DESC"
            );
            $st->execute([$source, $parlId]);
            return $wrap(array_map(fn($r) => [
                '__str__'                  => $r['materia_str'],
                'materia'                  => $r['materia_id'] ? (int)$r['materia_id'] : null,
                'comissao'                 => ['__str__' => $r['comissao_str']],
                'data_designacao_relator'  => $r['data_designacao'],
                'data_destituicao_relator' => $r['data_destituicao'],
            ], $st->fetchAll(PDO::FETCH_ASSOC)));
        }

        if (str_contains($path, '/parlamentares/frenteparlamentar') && $parlId) {
            $st = $db->prepare(
                "SELECT frente_id, frente_nome, cargo
                 FROM parl_frentes WHERE source_key = ? AND sapl_id = ?
                 ORDER BY frente_nome"
            );
            $st->execute([$source, $parlId]);
            return $wrap(array_map(fn($r) => [
                'frente'      => $r['frente_id'] ? (int)$r['frente_id'] : null,
                '__str__'     => $r['frente_nome'],
                'cargo'       => $r['cargo'],
                'data_entrada'=> null,
                'data_saida'  => null,
            ], $st->fetchAll(PDO::FETCH_ASSOC)));
        }

        // Lista global de frentes (usada pelo frontend para enriquecer nomes)
        if (str_contains($path, '/parlamentares/frente/') && !$parlId) {
            $st = $db->prepare(
                "SELECT frente_id, frente_nome
                 FROM parl_frentes WHERE source_key = ?
                 GROUP BY frente_id, frente_nome ORDER BY frente_nome"
            );
            $st->execute([$source]);
            return $wrap(array_map(fn($r) => [
                'id'   => (int)$r['frente_id'],
                'nome' => $r['frente_nome'],
            ], $st->fetchAll(PDO::FETCH_ASSOC)));
        }

        // Cargos de frentes — retorna lista de cargos únicos das frentes da fonte
        if (str_contains($path, '/parlamentares/frentecargo/')) {
            $st = $db->prepare(
                "SELECT DISTINCT cargo FROM parl_frentes WHERE source_key = ? AND cargo IS NOT NULL AND cargo != '' ORDER BY cargo"
            );
            $st->execute([$source]);
            $cargos = $st->fetchAll(PDO::FETCH_COLUMN);
            return $wrap(array_map(fn($i, $c) => ['id' => $i + 1, 'descricao' => $c], array_keys($cargos), $cargos));
        }

        // Detalhe de uma matéria por ID — parl_materias_detalhe primeiro (todas as fontes pré-sincronizadas)
        if (preg_match('#/materia/materialegislativa/(\d+)/#', $path, $m)) {
            $materiaId = (int)$m[1];
            $st = $db->prepare(
                "SELECT * FROM parl_materias_detalhe WHERE source_key = ? AND materia_id = ? LIMIT 1"
            );
            $st->execute([$source, $materiaId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                return [
                    'id'               => (int)$r['materia_id'],
                    '__str__'          => $r['descricao'],
                    'tipo'             => ['sigla' => $r['tipo_sigla'], 'descricao' => $r['tipo_descricao'] ?: $r['tipo_sigla']],
                    'numero'           => $r['numero'],
                    'ano'              => $r['ano'] ? (int)$r['ano'] : null,
                    'ementa'           => $r['ementa'],
                    'data_apresentacao'=> $r['data_apresentacao'],
                    'data'             => $r['data_apresentacao'],
                    'situacao'         => $r['situacao'],
                    'orgao_atual'      => $r['orgao_atual'],
                    'regime_tramitacao'=> $r['regime_tramitacao'] ? ['id' => 0, 'descricao' => $r['regime_tramitacao']] : null,
                    'despacho_atual'   => $r['despacho_atual'],
                    'palavras_chave'   => $r['palavras_chave'],
                    'em_tramitacao'    => (bool)$r['em_tramitacao'],
                    'texto_original'   => $r['texto_url'],
                    'texto_integral'   => $r['texto_url'],
                ];
            }
            // parl_materias_detalhe vazio — extrai dados de parl_materias.descricao
            // Formato armazenado: "TIPO nº NUM/ANO - ementa completa"
            if ($source === 'camara_federal') {
                $stB = $db->prepare(
                    "SELECT materia_id, tipo_sigla, numero, ano, ementa, descricao, data_apresentacao, situacao
                     FROM parl_materias WHERE source_key = ? AND materia_id = ? LIMIT 1"
                );
                $stB->execute([$source, $materiaId]);
                $b = $stB->fetch(PDO::FETCH_ASSOC);
                if ($b) {
                    $desc   = $b['descricao'] ?? '';
                    $tipo   = $b['tipo_sigla'] ?? '';
                    $numero = $b['numero'] ?? '';
                    $ano    = $b['ano'] ? (int)$b['ano'] : null;
                    $ementa = $b['ementa'] ?? '';

                    // Parseia "TIPO nº NUM/ANO - ementa" quando campos estão vazios
                    if ((!$tipo || !$numero) && preg_match(
                        '/^([A-Z][A-Z0-9\-]+)\s+n[°º]?\s*([\d.]+)\/(\d{4})\s*-\s*(.+)/u',
                        $desc, $pm
                    )) {
                        $tipo   = $tipo   ?: $pm[1];
                        $numero = $numero ?: $pm[2];
                        $ano    = $ano    ?: (int)$pm[3];
                        $ementa = $ementa ?: trim($pm[4]);
                    }

                    return [
                        'id'               => (int)$b['materia_id'],
                        '__str__'          => $desc,
                        'tipo'             => ['sigla' => $tipo, 'descricao' => $tipo],
                        'numero'           => $numero,
                        'ano'              => $ano,
                        'ementa'           => $ementa ?: null,
                        'data_apresentacao'=> $b['data_apresentacao'],
                        'data'             => $b['data_apresentacao'],
                        'situacao'         => $b['situacao'] ?: null,
                        'em_tramitacao'    => null,
                    ];
                }
                return ['id' => null, '__str__' => 'Proposição não encontrada'];
            }
            if ($source === 'senado') return null;
        }

        // Detalhe via sapl_cache / parl_materias (fontes SAPL sem pré-sync ainda)
        if (preg_match('#/materia/materialegislativa/(\d+)/#', $path, $m) && !in_array($source, ['senado', 'camara_federal'])) {
            $materiaId = (int)$m[1];

            // 1. Tenta sapl_cache (resposta completa da API SAPL)
            $cached = SaplCache::get($source, "/materia/materialegislativa/{$materiaId}/");
            if ($cached !== null) {
                $data = json_decode($cached, true);
                if (!empty($data['id'])) return $data;
            }

            // 2. Fallback: parl_materias (dados básicos do sync) — usado só se a API ainda não foi chamada
            $st = $db->prepare(
                "SELECT materia_id, tipo_sigla, numero, ano, ementa, descricao, data_apresentacao, situacao
                 FROM parl_materias WHERE source_key = ? AND materia_id = ? LIMIT 1"
            );
            $st->execute([$source, $materiaId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $desc = $r['descricao'] ?? '';
                if (str_starts_with($desc, 'Autoria:')) {
                    $dp = strpos($desc, ' - ');
                    if ($dp !== false) $desc = substr($desc, $dp + 3);
                }
                $parsedTipo = null; $parsedNum = null; $parsedAno = null;
                if (preg_match('/^(.+?)\s+n[°º]\s*([\d\.]+)(?:\s+de\s+(\d{4}))?/u', $desc, $pm)) {
                    $parsedTipo = trim($pm[1]);
                    $parsedNum  = $pm[2] ?? null;
                    $parsedAno  = !empty($pm[3]) ? (int)$pm[3] : null;
                }
                return [
                    'id'                => (int)$r['materia_id'],
                    '__str__'           => $desc ?: $r['descricao'],
                    'tipo'              => ['sigla' => $r['tipo_sigla'] ?: $parsedTipo, 'descricao' => $r['tipo_sigla'] ?: $parsedTipo],
                    'numero'            => ($r['numero'] !== '' && $r['numero'] !== null) ? $r['numero'] : $parsedNum,
                    'ano'               => ($r['ano'] !== '' && $r['ano'] !== null) ? (int)$r['ano'] : $parsedAno,
                    'ementa'            => $r['ementa'] ?: null,
                    'data_apresentacao' => $r['data_apresentacao'] ?: null,
                    'situacao'          => $r['situacao'] ?: null,
                    'em_tramitacao'     => $r['situacao'] ? ($r['situacao'] !== 'Arquivada') : null,
                ];
            }

            // 3. Sem dados locais: deixa cair no proxy → API ao vivo → cacheia automaticamente
            return null;
        }

        // Tramitação — parl_materias_tramitacao primeiro (fontes pré-sincronizadas)
        if (str_contains($path, '/materia/tramitacao') && isset($extra['materia'])) {
            $mId = (int)$extra['materia'];
            $st  = $db->prepare(
                "SELECT sequencia, data_tramitacao, status_str, destino_str, regime, texto, url
                 FROM parl_materias_tramitacao
                 WHERE source_key = ? AND materia_id = ?
                 ORDER BY sequencia DESC"
            );
            $st->execute([$source, $mId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $mapped = array_map(fn($r) => [
                'data_tramitacao'            => $r['data_tramitacao'],
                'status'                     => $r['status_str'],
                'regime'                     => $r['regime'] ?: null,
                'unidade_tramitacao_destino' => $r['destino_str'] ? ['nome' => $r['destino_str']] : null,
                'texto'                      => $r['texto'],
                'url'                        => $r['url'] ?: null,
            ], $rows);

            if ($rows) return $wrap($mapped);

            // Câmara Federal: sem dados no banco → retorna vazio em vez de cair na API
            if ($source === 'camara_federal') return $wrap([]);
        }

        // Tramitação via relatorias — fallback para fontes cujo API de tramitação não funciona (ex: ALPB)
        if (str_contains($path, '/materia/tramitacao') && isset($extra['materia']) && $source === 'alpb') {
            $mId = (int)$extra['materia'];
            $st = $db->prepare(
                "SELECT pr.materia_str, pr.data_designacao, pr.data_destituicao,
                        pp.nome_parlamentar
                 FROM parl_relatorias pr
                 LEFT JOIN parl_parlamentares pp ON pp.source_key=pr.source_key AND pp.sapl_id=pr.sapl_id
                 WHERE pr.source_key=? AND pr.materia_id=?
                 ORDER BY pr.data_designacao DESC"
            );
            $st->execute([$source, $mId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $tramitacoes = [];
            foreach ($rows as $r) {
                $parts = array_map('trim', explode(' - ', $r['materia_str'] ?? ''));
                array_shift($parts); // remove "TIPO nº NUM de ANO" prefix

                $dateStr = $r['data_designacao'] ?? null;
                $statusParts = [];
                foreach ($parts as $p) {
                    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $p, $dm)) {
                        $dateStr = "{$dm[3]}-{$dm[2]}-{$dm[1]}";
                    } elseif ($p !== '') {
                        $statusParts[] = $p;
                    }
                }

                $statusText = implode(' — ', $statusParts) ?: 'Relatoria';
                $relator    = $r['nome_parlamentar'] ?? null;

                $tramitacoes[] = [
                    'data_tramitacao'            => $dateStr,
                    'status'                     => ['descricao' => $statusText],
                    '__str__'                    => $statusText,
                    'unidade_tramitacao_destino' => $relator ? ['nome' => 'Rel.: ' . $relator] : null,
                    'texto'                      => '',
                ];

                if (!empty($r['data_destituicao'])) {
                    $tramitacoes[] = [
                        'data_tramitacao'            => $r['data_destituicao'],
                        'status'                     => ['descricao' => 'Destituição do relator'],
                        '__str__'                    => 'Destituição do relator',
                        'unidade_tramitacao_destino' => $relator ? ['nome' => 'Ex-Rel.: ' . $relator] : null,
                        'texto'                      => '',
                    ];
                }
            }

            usort($tramitacoes, fn($a, $b) => strcmp($b['data_tramitacao'] ?? '', $a['data_tramitacao'] ?? ''));
            return $wrap($tramitacoes);
        }

        // Autores de uma matéria específica por materia_id (chamado ao abrir detalhe)
        if (str_contains($path, '/materia/autoria') && isset($extra['materia'])) {
            $mId = (int)$extra['materia'];

            // Câmara Federal: usa parl_materias_autores (lista completa com coautores)
            if ($source === 'camara_federal') {
                $stA = $db->prepare(
                    "SELECT nome_autor, tipo_autor, id_deputado_autor, sigla_partido, sigla_uf,
                            ordem_assinatura, proponente
                     FROM parl_materias_autores
                     WHERE source_key = ? AND materia_id = ?
                     ORDER BY ordem_assinatura"
                );
                $stA->execute([$source, $mId]);
                return $wrap(array_map(fn($r) => [
                    '__str__'        => $r['nome_autor'],
                    'nome'           => $r['nome_autor'],
                    'tipo'           => $r['tipo_autor'],
                    'id_deputado'    => $r['id_deputado_autor'] ? (int)$r['id_deputado_autor'] : null,
                    'partido'        => $r['sigla_partido'],
                    'uf'             => $r['sigla_uf'],
                    'ordem'          => (int)$r['ordem_assinatura'],
                    'primeiro_autor' => (bool)$r['proponente'],
                ], $stA->fetchAll(PDO::FETCH_ASSOC)));
            }

            // Fontes SAPL: extrai autor do campo descricao
            $st  = $db->prepare(
                "SELECT materia_id, descricao, primeiro_autor FROM parl_materias
                 WHERE source_key = ? AND materia_id = ? LIMIT 1"
            );
            $st->execute([$source, $mId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $desc = $r['descricao'] ?? '';
                $autorNome = null;
                if (preg_match('/^Autoria:\s*(.+?)\s*-\s+/u', $desc, $am)) {
                    $autorNome = trim($am[1]);
                }
                if (!$autorNome) $autorNome = $desc ?: 'Desconhecido';
                return $wrap([['__str__' => $autorNome, 'primeiro_autor' => (bool)$r['primeiro_autor']]]);
            }
            return $wrap([]);
        }

        // Documentos acessórios — não temos no banco; evita chamada API ao vivo para camara_federal
        if ($source === 'camara_federal' && str_contains($path, '/materia/documentosacessorio')) {
            return $wrap([]);
        }

        // Temas de uma proposição — parl_materias_temas (camara_federal)
        if ($source === 'camara_federal' && str_contains($path, '/materia/tema') && isset($extra['materia'])) {
            $mId = (int)$extra['materia'];
            $st  = $db->prepare(
                "SELECT cod_tema, tema, relevancia FROM parl_materias_temas
                 WHERE source_key = ? AND materia_id = ? ORDER BY relevancia DESC, tema"
            );
            $st->execute([$source, $mId]);
            return $wrap(array_map(fn($r) => [
                'cod_tema'  => (int)$r['cod_tema'],
                'tema'      => $r['tema'],
                'relevancia'=> (int)$r['relevancia'],
            ], $st->fetchAll(PDO::FETCH_ASSOC)));
        }

        if (str_contains($path, '/materia/autoria') && $autorId) {
            $st = $db->prepare(
                "SELECT materia_id, tipo_sigla, numero, ano, ementa, descricao, primeiro_autor
                 FROM parl_materias WHERE source_key = ? AND sapl_id = ?
                 ORDER BY ano DESC, CAST(numero AS UNSIGNED) DESC"
            );
            $st->execute([$source, $autorId]);
            return $wrap(array_map(fn($r) => [
                'id'             => $r['materia_id'] ? (int)$r['materia_id'] : null,
                'materia'        => $r['materia_id'] ? (int)$r['materia_id'] : null,
                '__str__'        => $r['descricao'],
                'tipo'           => ['sigla' => $r['tipo_sigla'], 'descricao' => $r['tipo_sigla']],
                'numero'         => $r['numero'],
                'ano'            => $r['ano'] ? (int)$r['ano'] : null,
                'ementa'         => $r['ementa'],
                'primeiro_autor' => (bool)$r['primeiro_autor'],
            ], $st->fetchAll(PDO::FETCH_ASSOC)));
        }

        if (str_contains($path, '/norma/autorianorma') && $autorId) {
            $st = $db->prepare(
                "SELECT norma_id, tipo_sigla, numero, ano, ementa, data_norma, texto_integral, descricao
                 FROM parl_normas WHERE source_key = ? AND sapl_id = ?
                 ORDER BY ano DESC, CAST(numero AS UNSIGNED) DESC"
            );
            $st->execute([$source, $autorId]);
            return $wrap(array_map(fn($r) => [
                '__str__'        => $r['descricao'],
                'norma'          => $r['norma_id'] ? (int)$r['norma_id'] : null,
                'tipo'           => $r['tipo_sigla'],
                'numero'         => $r['numero'],
                'ano'            => $r['ano'] ? (int)$r['ano'] : null,
                'ementa'         => $r['ementa'],
                'data'           => $r['data_norma'],
                'texto_integral' => $r['texto_integral'],
                'primeiro_autor' => true,
            ], $st->fetchAll(PDO::FETCH_ASSOC)));
        }

        // Detalhe de norma por ID — serve do banco para todas as fontes
        if (preg_match('#/norma/normajuridica/(\d+)/#', $path, $m)) {
            $normaId = (int)$m[1];
            $st = $db->prepare(
                "SELECT norma_id, tipo_sigla, numero, ano, ementa, data_norma, texto_integral, descricao
                 FROM parl_normas WHERE source_key = ? AND norma_id = ? LIMIT 1"
            );
            $st->execute([$source, $normaId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                // Quando campos estão vazios, parseia do __str__
                $desc = $r['descricao'] ?? '';
                if (str_starts_with($desc, 'Autoria:')) {
                    $dp = strpos($desc, ' - ');
                    if ($dp !== false) $desc = substr($desc, $dp + 3);
                }
                $parsedTipo = null; $parsedNum = null; $parsedAno = null; $parsedData = null;
                if (preg_match('/^(.+?)\s+n[°º]\s*([\d\.]+)(?:,\s*de\s+(\d{1,2})\s+de\s+(\w+)\s+de\s+(\d{4}))?/u', $desc, $pm)) {
                    $parsedTipo = trim($pm[1]);
                    $parsedNum  = $pm[2] ?? null;
                    if (!empty($pm[5])) {
                        $parsedAno = $pm[5];
                        $meses = ['janeiro'=>'01','fevereiro'=>'02','março'=>'03','abril'=>'04','maio'=>'05','junho'=>'06',
                                  'julho'=>'07','agosto'=>'08','setembro'=>'09','outubro'=>'10','novembro'=>'11','dezembro'=>'12'];
                        $mn = $meses[mb_strtolower($pm[4] ?? '')] ?? null;
                        if ($mn) $parsedData = $pm[5].'-'.$mn.'-'.str_pad($pm[3], 2, '0', STR_PAD_LEFT);
                    }
                }
                return [
                    'id'             => (int)$r['norma_id'],
                    '__str__'        => $r['descricao'],
                    'tipo'           => $r['tipo_sigla'] ?: $parsedTipo,
                    'numero'         => $r['numero'] ?: $parsedNum,
                    'ano'            => $r['ano'] ? (int)$r['ano'] : ($parsedAno ? (int)$parsedAno : null),
                    'ementa'         => $r['ementa'] ?: null,
                    'data'           => $r['data_norma'] ?: $parsedData,
                    'texto_integral' => $r['texto_integral'],
                ];
            }
            return ['id' => null, '__str__' => 'Norma não encontrada'];
        }

        if (str_contains($path, '/emendas/') && $parlId) {
            $ano = (int)($extra['ano'] ?? 0);
            if (!$ano) { preg_match('/[?&]ano=(\d+)/', $path, $m); $ano = (int)($m[1] ?? 0); }
            $anoWhere = $ano ? ' AND ano = ?' : '';
            $st  = $db->prepare(
                "SELECT emenda_cod, numero, ano, tipo, localidade, funcao, subfuncao,
                        orgao, acao, programa,
                        valor_dotacao, valor_empenhado, valor_liquidado, valor_pago, descricao
                 FROM parl_emendas
                 WHERE source_key = ? AND parlamentar_id = ? $anoWhere
                 ORDER BY ano DESC, valor_dotacao DESC"
            );
            $params = [$source, $parlId];
            if ($ano) $params[] = $ano;
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // Carrega breakdown por município para emendas com múltiplos destinos
            $codigos = array_values(array_filter(array_unique(array_column($rows, 'emenda_cod'))));
            $municipiosMap = [];
            if ($codigos) {
                $ph = implode(',', array_fill(0, count($codigos), '?'));
                $stMun = $db->prepare(
                    "SELECT emenda_cod, municipio, uf, valor_empenhado, valor_liquidado, valor_pago
                     FROM parl_emendas_municipios
                     WHERE source_key = ? AND emenda_cod IN ($ph)
                     ORDER BY valor_empenhado DESC"
                );
                $stMun->execute([$source, ...$codigos]);
                foreach ($stMun->fetchAll(PDO::FETCH_ASSOC) as $m) {
                    $municipiosMap[$m['emenda_cod']][] = [
                        'municipio' => $m['municipio'],
                        'uf'        => $m['uf'],
                        'emp'       => (float)$m['valor_empenhado'],
                        'liq'       => (float)$m['valor_liquidado'],
                        'pag'       => (float)$m['valor_pago'],
                    ];
                }
            }

            return $wrap(array_map(fn($r) => [
                '__str__'          => trim("{$r['tipo']} nº {$r['numero']}/{$r['ano']}" . ($r['localidade'] ? " — {$r['localidade']}" : '')),
                'id'               => $r['emenda_cod'],
                'tipo'             => $r['tipo'],
                'numero'           => $r['numero'],
                'ano'              => (int)$r['ano'],
                'localidade'       => $r['localidade'],
                'funcao'           => $r['funcao'],
                'subfuncao'        => $r['subfuncao'],
                'orgao'            => $r['orgao'],
                'acao'             => $r['acao'],
                'programa'         => $r['programa'],
                'valor_dotacao'    => (float)$r['valor_dotacao'],
                'valor_empenhado'  => (float)$r['valor_empenhado'],
                'valor_liquidado'  => (float)$r['valor_liquidado'],
                'valor_pago'       => (float)$r['valor_pago'],
                'municipios'       => $municipiosMap[$r['emenda_cod']] ?? [],
            ], $rows));
        }

        return null; // endpoint não gerenciado — passa para sapl_cache/API
    }

    public function bulk(): void {
        $this->requireAuth();
        session_write_close();
        $source = $_GET['source'] ?? DEFAULT_SOURCE;
        $db     = Database::connect();

        $st = $db->prepare(
            "SELECT pp.sapl_id, pp.nome_completo, pp.nome_parlamentar, pp.partido_sigla, pp.uf,
                    pp.fotografia_url, pp.email, pp.ativo, pp.titular,
                    pd.situacao, pd.biografia, pd.profissao, pd.escolaridade,
                    pd.homepage, pd.gabinete, pd.telefone,
                    pd.data_nascimento, pd.municipio_nascimento, pd.uf_nascimento
             FROM parl_parlamentares pp
             LEFT JOIN parl_perfil_detalhe pd
               ON pd.source_key = pp.source_key AND pd.sapl_id = pp.sapl_id
             WHERE pp.source_key = ? ORDER BY pp.nome_parlamentar"
        );
        $st->execute([$source]);
        $parlamentares = array_map(fn($r) => [
            'id'               => (int)$r['sapl_id'],
            'nome_completo'    => $r['nome_completo'],
            'nome_parlamentar' => $r['nome_parlamentar'],
            'partido'          => ['sigla' => $r['partido_sigla'] ?? ''],
            'uf'               => $r['uf'] ?? '',
            'fotografia'       => $r['fotografia_url'] ?? '',
            'email'            => $r['email'] ?? '',
            'ativo'            => (bool)$r['ativo'],
            'titular'          => (bool)($r['titular'] ?? true),
            'situacao'         => $r['situacao'] ?? '',
            'biografia'        => $r['biografia'] ?? '',
            'profissao'        => $r['profissao'] ?? '',
            'escolaridade'     => $r['escolaridade'] ?? '',
            'homepage'         => $r['homepage'] ?? '',
            'gabinete'         => $r['gabinete'] ?? '',
            'telefone'         => $r['telefone'] ?? '',
            'data_nascimento'  => $r['data_nascimento'] ?? '',
            'municipio_nascimento' => $r['municipio_nascimento'] ?? '',
            'uf_nascimento'    => $r['uf_nascimento'] ?? '',
        ], $st->fetchAll(PDO::FETCH_ASSOC));

        $st = $db->prepare(
            "SELECT sapl_id, numero, data_inicio, data_fim
             FROM parl_legislaturas WHERE source_key = ? ORDER BY numero DESC"
        );
        $st->execute([$source]);
        $legislaturas = array_map(fn($r) => [
            'id'          => (int)$r['sapl_id'],
            'numero'      => (int)$r['numero'],
            'data_inicio' => $r['data_inicio'],
            'data_fim'    => $r['data_fim'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));

        $st = $db->prepare(
            "SELECT sapl_id, sigla, nome
             FROM parl_partidos WHERE source_key = ? ORDER BY sigla"
        );
        $st->execute([$source]);
        $partidos = array_map(fn($r) => [
            'id'    => (int)$r['sapl_id'],
            'sigla' => $r['sigla'],
            'nome'  => $r['nome'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));

        $this->json([
            'parlamentares' => $parlamentares,
            'legislaturas'  => $legislaturas,
            'partidos'      => $partidos,
            'fromCache'     => !empty($parlamentares),
        ]);
    }

    public function agenteContexto(): void {
        $this->requireAuth();
        session_write_close();

        $source = $_GET['source'] ?? DEFAULT_SOURCE;
        $saplId = (int)($_GET['sapl_id'] ?? $_GET['parlamentar'] ?? 0);
        if ($saplId <= 0) {
            $this->json(['error' => 'Parlamentar inválido'], 400);
            return;
        }

        $db = Database::connect();
        $rows = function (string $sql, array $params = []) use ($db): array {
            $st = $db->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        };

        $perfil = $rows(
            "SELECT pp.sapl_id, pp.nome_completo, pp.nome_parlamentar, pp.partido_sigla, pp.uf,
                    pp.ativo, pd.profissao, pd.escolaridade
             FROM parl_parlamentares pp
             LEFT JOIN parl_perfil_detalhe pd
               ON pd.source_key = pp.source_key AND pd.sapl_id = pp.sapl_id
             WHERE pp.source_key = ? AND pp.sapl_id = ?
             LIMIT 1",
            [$source, $saplId]
        );

        $materias = $rows(
            "SELECT materia_id, tipo_sigla, numero, ano, ementa, descricao, primeiro_autor
             FROM parl_materias
             WHERE source_key = ? AND sapl_id = ?
             ORDER BY ano DESC, CAST(numero AS UNSIGNED) DESC",
            [$source, $saplId]
        );

        $normas = $rows(
            "SELECT norma_id, tipo_sigla, numero, ano, ementa, data_norma, texto_integral, descricao
             FROM parl_normas
             WHERE source_key = ? AND sapl_id = ?
             ORDER BY ano DESC, CAST(numero AS UNSIGNED) DESC",
            [$source, $saplId]
        );

        $emendas = $rows(
            "SELECT emenda_cod, numero, ano, tipo, localidade, funcao, subfuncao,
                    orgao, acao, programa,
                    valor_dotacao, valor_empenhado, valor_liquidado, valor_pago, descricao
             FROM parl_emendas
             WHERE source_key = ? AND parlamentar_id = ?
             ORDER BY ano DESC, valor_dotacao DESC",
            [$source, $saplId]
        );

        $comissoes = $rows(
            "SELECT comissao_str, data_inicio, data_fim, titular, comissao_id
             FROM parl_comissoes
             WHERE source_key = ? AND sapl_id = ?
             ORDER BY data_inicio DESC",
            [$source, $saplId]
        );

        $filiacoes = $rows(
            "SELECT partido_sigla, partido_nome, data_filiacao, data_desfiliacao, atual
             FROM parl_filiacoes
             WHERE source_key = ? AND sapl_id = ?
             ORDER BY data_filiacao DESC",
            [$source, $saplId]
        );

        $mandatos = $rows(
            "SELECT parlamentar_id, legislatura_id, titular, votos_recebidos, coligacao
             FROM parl_mandatos
             WHERE source_key = ? AND parlamentar_id = ?
             ORDER BY legislatura_id DESC",
            [$source, $saplId]
        );

        $frentes = $rows(
            "SELECT frente_id, frente_nome, cargo, ativa
             FROM parl_frentes
             WHERE source_key = ? AND sapl_id = ?
             ORDER BY frente_nome",
            [$source, $saplId]
        );

        $relatorias = $rows(
            "SELECT materia_id, materia_str, comissao_str, data_designacao, data_destituicao
             FROM parl_relatorias
             WHERE source_key = ? AND sapl_id = ?
             ORDER BY data_designacao DESC",
            [$source, $saplId]
        );

        $this->json([
            'perfil' => $perfil[0] ?? null,
            'materias' => array_map(fn($r) => [
                'id'             => $r['materia_id'] ? (int)$r['materia_id'] : null,
                'materia'        => $r['materia_id'] ? (int)$r['materia_id'] : null,
                '__str__'        => $r['descricao'],
                'tipo'           => ['sigla' => $r['tipo_sigla'], 'descricao' => $r['tipo_sigla']],
                'numero'         => $r['numero'],
                'ano'            => $r['ano'] ? (int)$r['ano'] : null,
                'ementa'         => $r['ementa'],
                'primeiro_autor' => (bool)$r['primeiro_autor'],
            ], $materias),
            'normas' => array_map(fn($r) => [
                '__str__'        => $r['descricao'],
                'norma'          => $r['norma_id'] ? (int)$r['norma_id'] : null,
                'tipo'           => $r['tipo_sigla'],
                'numero'         => $r['numero'],
                'ano'            => $r['ano'] ? (int)$r['ano'] : null,
                'ementa'         => $r['ementa'],
                'data'           => $r['data_norma'],
                'texto_integral' => $r['texto_integral'],
                'primeiro_autor' => true,
            ], $normas),
            'emendas' => array_map(fn($r) => [
                '__str__'          => trim("{$r['tipo']} nº {$r['numero']}/{$r['ano']}" . ($r['localidade'] ? " - {$r['localidade']}" : '')),
                'id'               => $r['emenda_cod'],
                'tipo'             => $r['tipo'],
                'numero'           => $r['numero'],
                'ano'              => (int)$r['ano'],
                'localidade'       => $r['localidade'],
                'funcao'           => $r['funcao'],
                'subfuncao'        => $r['subfuncao'],
                'orgao'            => $r['orgao'],
                'acao'             => $r['acao'],
                'programa'         => $r['programa'],
                'valor_dotacao'    => (float)$r['valor_dotacao'],
                'valor_empenhado'  => (float)$r['valor_empenhado'],
                'valor_liquidado'  => (float)$r['valor_liquidado'],
                'valor_pago'       => (float)$r['valor_pago'],
            ], $emendas),
            'comissoes' => array_map(fn($r) => [
                '__str__'           => $r['comissao_str'],
                'data_designacao'   => $r['data_inicio'],
                'data_desligamento' => $r['data_fim'],
                'titular'           => (bool)$r['titular'],
                'comissao_id'       => $r['comissao_id'] ? (int)$r['comissao_id'] : null,
            ], $comissoes),
            'filiacoes' => array_map(fn($r) => [
                '__str__'          => $r['partido_sigla'] ?: $r['partido_nome'],
                'partido'          => $r['partido_sigla'] ?: $r['partido_nome'],
                'data'             => $r['data_filiacao'],
                'data_desfiliacao' => $r['data_desfiliacao'],
                'atual'            => (bool)$r['atual'],
            ], $filiacoes),
            'mandatos' => array_map(fn($r) => [
                'parlamentar'     => (int)$r['parlamentar_id'],
                'legislatura'     => (int)$r['legislatura_id'],
                'legislatura_id'  => (int)$r['legislatura_id'],
                'titular'         => (bool)$r['titular'],
                'votos_recebidos' => $r['votos_recebidos'],
                'coligacao'       => $r['coligacao'],
            ], $mandatos),
            'frentes' => array_map(fn($r) => [
                'frente'      => $r['frente_id'] ? (int)$r['frente_id'] : null,
                '__str__'     => $r['frente_nome'],
                'titulo'      => $r['frente_nome'],
                'cargo'       => $r['cargo'],
                'ativa'       => (bool)$r['ativa'],
            ], $frentes),
            'relatorias' => array_map(fn($r) => [
                '__str__'                  => $r['materia_str'],
                'materia'                  => $r['materia_id'] ? (int)$r['materia_id'] : null,
                'comissao'                 => ['__str__' => $r['comissao_str']],
                'data_designacao_relator'  => $r['data_designacao'],
                'data_destituicao_relator' => $r['data_destituicao'],
            ], $relatorias),
        ]);
    }

    public function dashboardGlobal(): void {
        $this->requireAuth();
        session_write_close();

        $source = $_GET['source'] ?? DEFAULT_SOURCE;
        $ano    = (int)($_GET['ano'] ?? 0);
        $partido = trim((string)($_GET['partido'] ?? ''));
        $uf      = strtoupper(trim((string)($_GET['uf'] ?? '')));
        $parlamentar = (int)($_GET['parlamentar'] ?? 0);
        $ativo   = $_GET['ativo'] ?? '1';
        $anoInicio = 2023;
        $anoFim    = 2026;
        if ($ano && ($ano < $anoInicio || $ano > $anoFim)) {
            $ano = 0;
        }

        $db = Database::connect();

        $parlWhere = ['pp.source_key = ?'];
        $parlParams = [$source];
        if ($partido !== '') {
            $parlWhere[] = 'pp.partido_sigla = ?';
            $parlParams[] = $partido;
        }
        if ($uf !== '') {
            $parlWhere[] = 'pp.uf = ?';
            $parlParams[] = $uf;
        }
        if ($parlamentar > 0) {
            $parlWhere[] = 'pp.sapl_id = ?';
            $parlParams[] = $parlamentar;
        }
        if ($ativo === '1' || $ativo === '0') {
            $parlWhere[] = 'pp.ativo = ?';
            $parlParams[] = (int)$ativo;
        }
        $parlWhereSql = implode(' AND ', $parlWhere);

        $rows = function (string $sql, array $params = []) use ($db): array {
            $st = $db->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        };
        $one = function (string $sql, array $params = []) use ($db): array {
            $st = $db->prepare($sql);
            $st->execute($params);
            return $st->fetch(PDO::FETCH_ASSOC) ?: [];
        };
        $series = fn(array $items, string $label = 'label', string $value = 'total'): array =>
            array_map(fn($r) => ['label' => (string)($r[$label] ?? ''), 'total' => (int)($r[$value] ?? 0)], $items);
        $moneySeries = fn(array $items, string $label = 'label'): array =>
            array_map(fn($r) => [
                'label'     => (string)($r[$label] ?? ''),
                'dotacao'   => (float)($r['dotacao'] ?? 0),
                'empenhado' => (float)($r['empenhado'] ?? 0),
                'liquidado' => (float)($r['liquidado'] ?? 0),
                'pago'      => (float)($r['pago'] ?? 0),
            ], $items);

        $anoMatWhere = $ano ? ' AND m.ano = ?' : ' AND m.ano BETWEEN ? AND ?';
        $anoNormWhere = $ano ? ' AND n.ano = ?' : ' AND n.ano BETWEEN ? AND ?';
        $anoEmWhere = $ano ? ' AND e.ano = ?' : ' AND e.ano BETWEEN ? AND ?';
        $yearParams = $ano ? [$ano] : [$anoInicio, $anoFim];
        $matParams = array_merge([$source], $yearParams, $parlParams);
        $normParams = array_merge([$source], $yearParams, $parlParams);
        $emParams = array_merge([$source], $yearParams, $parlParams);
        $dateYearWhere = $ano ? ' = ? ' : ' BETWEEN ? AND ? ';
        $dateYearParams = $ano ? [$ano] : [$anoInicio, $anoFim];

        $parlStats = $one(
            "SELECT COUNT(*) total, SUM(pp.ativo = 1) ativos
             FROM parl_parlamentares pp
             WHERE $parlWhereSql",
            $parlParams
        );

        $matStats = $one(
            "SELECT COUNT(*) total, SUM(m.primeiro_autor = 1) primeiro_autor
             FROM parl_materias m
             JOIN parl_parlamentares pp ON pp.source_key = m.source_key AND pp.sapl_id = m.sapl_id
             WHERE m.source_key = ? $anoMatWhere AND $parlWhereSql",
            $matParams
        );
        $normStats = $one(
            "SELECT COUNT(*) total
             FROM parl_normas n
             JOIN parl_parlamentares pp ON pp.source_key = n.source_key AND pp.sapl_id = n.sapl_id
             WHERE n.source_key = ? $anoNormWhere AND $parlWhereSql",
            $normParams
        );
        $emStats = $one(
            "SELECT COUNT(*) total,
                    SUM(e.valor_dotacao) dotacao,
                    SUM(e.valor_empenhado) empenhado,
                    SUM(e.valor_liquidado) liquidado,
                    SUM(e.valor_pago) pago
             FROM parl_emendas e
             JOIN parl_parlamentares pp ON pp.source_key = e.source_key AND pp.sapl_id = e.parlamentar_id
             WHERE e.source_key = ? $anoEmWhere AND $parlWhereSql",
            $emParams
        );
        $comStats = $one(
            "SELECT COUNT(*) comissoes
             FROM parl_comissoes c
             JOIN parl_parlamentares pp ON pp.source_key = c.source_key AND pp.sapl_id = c.sapl_id
             WHERE c.source_key = ? AND YEAR(c.data_inicio) $dateYearWhere AND $parlWhereSql",
            array_merge([$source], $dateYearParams, $parlParams)
        );
        $relStats = $one(
            "SELECT COUNT(*) relatorias
             FROM parl_relatorias r
             JOIN parl_parlamentares pp ON pp.source_key = r.source_key AND pp.sapl_id = r.sapl_id
             WHERE r.source_key = ? AND YEAR(r.data_designacao) $dateYearWhere AND $parlWhereSql",
            array_merge([$source], $dateYearParams, $parlParams)
        );
        $frenteStats = $one(
            "SELECT COUNT(*) frentes
             FROM parl_frentes f
             JOIN parl_parlamentares pp ON pp.source_key = f.source_key AND pp.sapl_id = f.sapl_id
             WHERE f.source_key = ? AND $parlWhereSql",
            array_merge([$source], $parlParams)
        );

        $materiasPorAno = $rows(
            "SELECT m.ano label, COUNT(*) total
             FROM parl_materias m
             JOIN parl_parlamentares pp ON pp.source_key = m.source_key AND pp.sapl_id = m.sapl_id
             WHERE m.source_key = ? $anoMatWhere AND m.ano IS NOT NULL AND $parlWhereSql
             GROUP BY m.ano ORDER BY m.ano",
            $matParams
        );
        $materiasPorTipo = $rows(
            "SELECT CASE
                        WHEN m.tipo_sigla <> '' THEN m.tipo_sigla
                        WHEN m.descricao REGEXP '^[A-Z][A-Z0-9-]*[[:space:]]' THEN SUBSTRING_INDEX(m.descricao, ' ', 1)
                        ELSE 'Outros'
                    END label,
                    COUNT(*) total
             FROM parl_materias m
             JOIN parl_parlamentares pp ON pp.source_key = m.source_key AND pp.sapl_id = m.sapl_id
             WHERE m.source_key = ? $anoMatWhere AND $parlWhereSql
             GROUP BY label ORDER BY total DESC",
            $matParams
        );
        $normasPorAno = $rows(
            "SELECT n.ano label, COUNT(*) total
             FROM parl_normas n
             JOIN parl_parlamentares pp ON pp.source_key = n.source_key AND pp.sapl_id = n.sapl_id
             WHERE n.source_key = ? $anoNormWhere AND n.ano IS NOT NULL AND $parlWhereSql
             GROUP BY n.ano ORDER BY n.ano",
            $normParams
        );
        $normasPorTipo = $rows(
            "SELECT COALESCE(NULLIF(n.tipo_sigla,''),'Outros') label, COUNT(*) total
             FROM parl_normas n
             JOIN parl_parlamentares pp ON pp.source_key = n.source_key AND pp.sapl_id = n.sapl_id
             WHERE n.source_key = ? $anoNormWhere AND $parlWhereSql
             GROUP BY label ORDER BY total DESC LIMIT 12",
            $normParams
        );
        $producaoPorPartido = $rows(
            "SELECT COALESCE(NULLIF(pp.partido_sigla,''),'Sem partido') label, COUNT(m.id) total
             FROM parl_materias m
             JOIN parl_parlamentares pp ON pp.source_key = m.source_key AND pp.sapl_id = m.sapl_id
             WHERE m.source_key = ? $anoMatWhere AND $parlWhereSql
             GROUP BY label ORDER BY total DESC",
            $matParams
        );
        $producaoPorUf = $rows(
            "SELECT COALESCE(NULLIF(pp.uf,''),'--') label, COUNT(m.id) total
             FROM parl_materias m
             JOIN parl_parlamentares pp ON pp.source_key = m.source_key AND pp.sapl_id = m.sapl_id
             WHERE m.source_key = ? $anoMatWhere AND $parlWhereSql
             GROUP BY label ORDER BY total DESC",
            $matParams
        );
        $emendasPorFuncao = $rows(
            "SELECT COALESCE(NULLIF(e.funcao,''),'Não classificado') label,
                    SUM(e.valor_dotacao) dotacao,
                    SUM(e.valor_empenhado) empenhado,
                    SUM(e.valor_liquidado) liquidado,
                    SUM(e.valor_pago) pago
             FROM parl_emendas e
             JOIN parl_parlamentares pp ON pp.source_key = e.source_key AND pp.sapl_id = e.parlamentar_id
             WHERE e.source_key = ? $anoEmWhere AND $parlWhereSql
             GROUP BY label ORDER BY empenhado DESC",
            $emParams
        );
        $emendasPorLocalidade = $rows(
            "SELECT label,
                    SUM(dotacao) dotacao, SUM(empenhado) empenhado,
                    SUM(liquidado) liquidado, SUM(pago) pago
             FROM (
               SELECT COALESCE(NULLIF(e.localidade,''),'Não informado') label,
                      e.valor_dotacao dotacao, e.valor_empenhado empenhado,
                      e.valor_liquidado liquidado, e.valor_pago pago
               FROM parl_emendas e
               JOIN parl_parlamentares pp ON pp.source_key = e.source_key AND pp.sapl_id = e.parlamentar_id
               WHERE e.source_key = ? $anoEmWhere AND $parlWhereSql
                 AND NOT EXISTS (SELECT 1 FROM parl_emendas_municipios em2
                                 WHERE em2.source_key = e.source_key AND em2.emenda_cod = e.emenda_cod AND em2.ano = e.ano)
               UNION ALL
               SELECT CONCAT(em.municipio, IF(em.uf != '' AND em.uf != '-1', CONCAT(' - ', em.uf), '')) label,
                      0 dotacao, em.valor_empenhado empenhado,
                      em.valor_liquidado liquidado, em.valor_pago pago
               FROM parl_emendas e
               JOIN parl_parlamentares pp ON pp.source_key = e.source_key AND pp.sapl_id = e.parlamentar_id
               JOIN parl_emendas_municipios em ON em.source_key = e.source_key AND em.emenda_cod = e.emenda_cod AND em.ano = e.ano
               WHERE e.source_key = ? $anoEmWhere AND $parlWhereSql
             ) t
             GROUP BY label ORDER BY empenhado DESC",
            array_merge($emParams, $emParams)
        );

        $subYearMat = $ano ? ' AND m.ano = ?' : ' AND m.ano BETWEEN ? AND ?';
        $subYearNorm = $ano ? ' AND ano = ?' : ' AND ano BETWEEN ? AND ?';
        $subYearEm = $ano ? ' AND ano = ?' : ' AND ano BETWEEN ? AND ?';
        $rankParams = array_merge(
            [$source], $yearParams,
            [$source], $yearParams,
            [$source], $yearParams,
            $parlParams
        );
        $ranking = $rows(
            "SELECT pp.sapl_id id, pp.nome_parlamentar, pp.nome_completo, pp.partido_sigla, pp.uf,
                    COALESCE(mt.total,0) materias,
                    COALESCE(mt.primeiro_autor,0) primeiro_autor,
                    COALESCE(nt.total,0) normas,
                    COALESCE(et.total,0) emendas,
                    COALESCE(et.dotacao,0) dotacao,
                    COALESCE(et.empenhado,0) empenhado,
                    COALESCE(et.liquidado,0) liquidado,
                    COALESCE(et.pago,0) pago
             FROM parl_parlamentares pp
             LEFT JOIN (
                 SELECT m.sapl_id, COUNT(*) total, SUM(m.primeiro_autor = 1) primeiro_autor
                 FROM parl_materias m
                 WHERE m.source_key = ? $subYearMat GROUP BY m.sapl_id
             ) mt ON mt.sapl_id = pp.sapl_id
             LEFT JOIN (
                 SELECT sapl_id, COUNT(*) total
                 FROM parl_normas WHERE source_key = ? $subYearNorm GROUP BY sapl_id
             ) nt ON nt.sapl_id = pp.sapl_id
             LEFT JOIN (
                 SELECT parlamentar_id, COUNT(*) total,
                        SUM(valor_dotacao) dotacao,
                        SUM(valor_empenhado) empenhado,
                        SUM(valor_liquidado) liquidado,
                        SUM(valor_pago) pago
                 FROM parl_emendas WHERE source_key = ? $subYearEm GROUP BY parlamentar_id
             ) et ON et.parlamentar_id = pp.sapl_id
             WHERE $parlWhereSql",
            $rankParams
        );

        $rankMap = function (array $ranking, string $field): array {
            usort($ranking, fn($a, $b) => ((float)$b[$field] <=> (float)$a[$field]) ?: strcmp($a['nome_parlamentar'] ?: $a['nome_completo'], $b['nome_parlamentar'] ?: $b['nome_completo']));
            $ranking = array_filter($ranking, fn($r) => (float)($r[$field] ?? 0) > 0);
            return array_map(fn($r) => [
                'id'             => (int)$r['id'],
                'nome'           => $r['nome_parlamentar'] ?: $r['nome_completo'],
                'partido'        => $r['partido_sigla'],
                'uf'             => $r['uf'],
                'materias'       => (int)$r['materias'],
                'primeiro_autor' => (int)$r['primeiro_autor'],
                'normas'         => (int)$r['normas'],
                'emendas'        => (int)$r['emendas'],
                'dotacao'        => (float)$r['dotacao'],
                'empenhado'      => (float)$r['empenhado'],
                'liquidado'      => (float)$r['liquidado'],
                'pago'           => (float)$r['pago'],
                'valor'          => (float)$r[$field],
            ], $ranking);
        };

        $years = [];
        foreach ([
            "SELECT DISTINCT ano FROM parl_materias WHERE source_key=? AND ano BETWEEN ? AND ?",
            "SELECT DISTINCT ano FROM parl_normas WHERE source_key=? AND ano BETWEEN ? AND ?",
            "SELECT DISTINCT ano FROM parl_emendas WHERE source_key=? AND ano BETWEEN ? AND ?",
        ] as $sql) {
            foreach ($rows($sql, [$source, $anoInicio, $anoFim]) as $r) $years[(int)$r['ano']] = true;
        }
        $years = array_keys($years);
        rsort($years);

        $partidos = $rows("SELECT DISTINCT partido_sigla sigla FROM parl_parlamentares WHERE source_key=? AND partido_sigla<>'' ORDER BY partido_sigla", [$source]);
        $ufs = $rows("SELECT DISTINCT uf FROM parl_parlamentares WHERE source_key=? AND uf<>'' ORDER BY uf", [$source]);
        $parlamentaresFiltro = $rows(
            "SELECT sapl_id id,
                    COALESCE(NULLIF(nome_parlamentar,''), nome_completo) nome,
                    partido_sigla partido,
                    uf
             FROM parl_parlamentares
             WHERE source_key = ?
             ORDER BY nome",
            [$source]
        );
        $totalMaterias = (int)($matStats['total'] ?? 0);
        $totalPrimeiro = (int)($matStats['primeiro_autor'] ?? 0);

        $this->json([
            'filters' => [
                'selected' => ['ano' => $ano ?: '', 'partido' => $partido, 'uf' => $uf, 'parlamentar' => $parlamentar ?: '', 'ativo' => $ativo],
                'periodo'  => ['inicio' => $anoInicio, 'fim' => $anoFim],
                'anos'     => $years,
                'partidos' => array_column($partidos, 'sigla'),
                'ufs'      => array_column($ufs, 'uf'),
                'parlamentares' => array_map(fn($r) => [
                    'id' => (int)$r['id'],
                    'nome' => $r['nome'],
                    'partido' => $r['partido'],
                    'uf' => $r['uf'],
                ], $parlamentaresFiltro),
            ],
            'kpis' => [
                'parlamentares' => (int)($parlStats['total'] ?? 0),
                'ativos'        => (int)($parlStats['ativos'] ?? 0),
                'materias'      => $totalMaterias,
                'primeiro_autor'=> $totalPrimeiro,
                'coautorias'    => max(0, $totalMaterias - $totalPrimeiro),
                'normas'        => (int)($normStats['total'] ?? 0),
                'emendas'       => (int)($emStats['total'] ?? 0),
                'dotacao'       => (float)($emStats['dotacao'] ?? 0),
                'empenhado'     => (float)($emStats['empenhado'] ?? 0),
                'liquidado'     => (float)($emStats['liquidado'] ?? 0),
                'pago'          => (float)($emStats['pago'] ?? 0),
                'comissoes'     => (int)($comStats['comissoes'] ?? 0),
                'relatorias'    => (int)($relStats['relatorias'] ?? 0),
                'frentes'       => (int)($frenteStats['frentes'] ?? 0),
            ],
            'charts' => [
                'materias_por_ano'      => $series($materiasPorAno),
                'materias_por_tipo'     => $series($materiasPorTipo),
                'normas_por_ano'        => $series($normasPorAno),
                'normas_por_tipo'       => $series($normasPorTipo),
                'producao_por_partido'  => $series($producaoPorPartido),
                'producao_por_uf'       => $series($producaoPorUf),
                'emendas_por_funcao'    => $moneySeries($emendasPorFuncao),
                'emendas_por_localidade'=> $moneySeries($emendasPorLocalidade),
            ],
            'rankings' => [
                'producao'           => $rankMap($ranking, 'materias'),
                'sancionadas'        => $rankMap($ranking, 'normas'),
                'emendas_quantidade' => $rankMap($ranking, 'emendas'),
                'emendas_valor'      => $rankMap($ranking, 'empenhado'),
            ],
        ]);
    }

    public function sincronizar(): void {
        $this->requireAuth();
        session_write_close();
        $source = $_GET['source'] ?? DEFAULT_SOURCE;

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        if (ob_get_level()) ob_end_clean();
        set_time_limit(300);

        $sse = function (array $data): void {
            echo 'data: ' . json_encode($data) . "\n\n";
            flush();
        };

        $paths = [
            'parlamentares' => '/parlamentares/parlamentar/',
            'legislaturas'  => '/parlamentares/legislatura/',
            'partidos'      => '/parlamentares/partido/',
        ];

        // Fase 1: descobre total de páginas (busca página 1 de cada recurso se necessário)
        $recursos   = [];
        $totalPages = 0;
        foreach ($paths as $key => $path) {
            $cacheKey = $path . '&page=1';
            $cached   = SaplCache::get($source, $cacheKey);
            if ($cached) {
                $decoded = json_decode($cached, true);
            } else {
                usleep(120_000);
                $raw     = SaplApi::getRaw($path, $source, ['page' => 1]);
                $decoded = json_decode($raw, true);
                if ($raw !== '{}' && $raw !== '{"__rate_limited":true}') {
                    SaplCache::set($source, $cacheKey, $raw, SaplCache::ttlFor($path));
                }
            }
            $pages              = $decoded['pagination']['total_pages'] ?? 1;
            $recursos[$key]     = ['path' => $path, 'pages' => $pages];
            $totalPages        += $pages;
        }

        $done = count($paths); // páginas 1 já processadas
        $sse(['status' => 'iniciando', 'total' => $totalPages, 'done' => $done]);

        // Fase 2: busca páginas restantes com delay para não acionar rate limit
        foreach ($recursos as $key => $info) {
            for ($pg = 2; $pg <= $info['pages']; $pg++) {
                if (connection_aborted()) exit;

                $cacheKey = $info['path'] . '&page=' . $pg;
                if (!SaplCache::get($source, $cacheKey)) {
                    usleep(120_000);
                    $raw = SaplApi::getRaw($info['path'], $source, ['page' => $pg]);
                    if ($raw !== '{}' && $raw !== '{"__rate_limited":true}') {
                        SaplCache::set($source, $cacheKey, $raw, SaplCache::ttlFor($info['path']));
                    }
                }

                $done++;
                $sse(['status' => 'progresso', 'done' => $done, 'total' => $totalPages, 'recurso' => $key]);
            }
        }

        $sse(['status' => 'concluido', 'done' => $totalPages, 'total' => $totalPages]);
        exit;
    }

    public function cacheInvalidar(): void {
        $this->requireAuth();
        $source  = $_POST['source'] ?? DEFAULT_SOURCE;
        $removed = SaplCache::invalidate($source);
        $this->json(['ok' => true, 'removidos' => $removed]);
    }

    public function cacheStatus(): void {
        $this->requireAuth();
        $source = $_GET['source'] ?? DEFAULT_SOURCE;
        $this->json(SaplCache::stats($source));
    }

    public function updateParlTotal(): void {
        $this->requireAuth();
        $projetoId = (int)Auth::projetoId();
        $total     = (int)($_POST['total'] ?? 0);
        if (!$projetoId || $total < 0) { $this->json(['ok' => false], 400); }
        $st = Database::connect()->prepare("UPDATE projetos SET parl_total = ? WHERE id = ?");
        $st->execute([$total, $projetoId]);
        $this->json(['ok' => true]);
    }

    public function arquivoStore(): void {
        $this->requireAuth();

        $projetoId = (int) ($_POST['projeto_id'] ?? 0);
        if (!$projetoId) { $this->json(['error' => 'Projeto inválido.'], 400); }

        $nome     = trim($_POST['nome']     ?? '');
        $conteudo = trim($_POST['conteudo'] ?? '');
        if (!$nome || !$conteudo) { $this->json(['error' => 'Nome e conteúdo são obrigatórios.'], 400); }

        $id = (new SentinelaArquivo())->addArquivo($projetoId, $nome, $conteudo);
        $this->json(['ok' => true, 'id' => $id]);
    }

    public function arquivoRemove(): void {
        $this->requireAuth();

        $id = (int) ($_POST['id'] ?? 0);
        if (!$id) { $this->json(['error' => 'ID inválido.'], 400); }

        (new SentinelaArquivo())->remove($id);
        $this->json(['ok' => true]);
    }

    public function img(): void {
        $this->requireAuth();
        session_write_close(); // libera lock de sessão para não bloquear requisições paralelas
        $source = $_GET['source'] ?? DEFAULT_SOURCE;
        $path   = trim($_GET['path'] ?? '');

        // Aceita caminhos de imagem (permite = para URLs do Senado como sufixo=fotoXXX.jpg)
        if (!$path || !preg_match('#^/[a-zA-Z0-9/_\-\.=]+\.(jpg|jpeg|png|gif|webp)$#i', $path)) {
            http_response_code(400);
            exit;
        }

        // Serve do arquivo local se existir (baixado por sync_fotos.php)
        if (str_starts_with($path, '/uploads/')) {
            $localFile = ROOT . '/public' . $path;
            if (file_exists($localFile)) {
                $ext   = strtolower(pathinfo($localFile, PATHINFO_EXTENSION));
                $ctype = match($ext) {
                    'png'  => 'image/png',
                    'gif'  => 'image/gif',
                    'webp' => 'image/webp',
                    default => 'image/jpeg',
                };
                header('Cache-Control: public, max-age=604800'); // 7 dias
                header('Content-Type: ' . $ctype);
                readfile($localFile);
                exit;
            }
        }

        // Fallback: proxy para o servidor do governo
        $fallbackUrl = null;
        if (preg_match('#^/uploads/parlamentares/([^/]+)/(\d+)\.(jpg|jpeg|png|gif|webp)$#i', $path, $m)) {
            $imgSource = $m[1];
            $imgId     = (int)$m[2];

            if ($source === 'camara_federal' || $imgSource === 'camara_federal') {
                $fallbackUrl = 'https://www.camara.leg.br/internet/deputado/bandep/' . $imgId . '.jpg';
            } elseif ($source !== 'senado') {
                $raw  = SaplApi::getRaw('/parlamentares/parlamentar/' . $imgId . '/', $source);
                $data = json_decode($raw, true) ?: [];
                $foto = $data['fotografia'] ?? ($data['results'][0]['fotografia'] ?? '');
                if ($foto) {
                    $fallbackUrl = str_starts_with($foto, 'http') ? $foto : rtrim(SaplApi::baseUrl($source), '/') . '/' . ltrim($foto, '/');
                }
            }
        }

        $imgDomains = [
            'camara_federal' => 'https://www.camara.leg.br',
            'senado'         => 'https://www.senado.leg.br',
        ];
        $base = $imgDomains[$source] ?? SaplApi::baseUrl($source);
        $url  = $fallbackUrl ?: $base . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'KeekConecta/1.0',
        ]);

        $body  = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (!$body || $code < 200 || $code >= 300) {
            http_response_code(404);
            exit;
        }

        header('Cache-Control: public, max-age=86400');
        header('Content-Type: ' . ($ctype ?: 'image/jpeg'));
        echo $body;
        exit;
    }

    public function sources(): void {
        $this->requireAuth();
        $sources = SOURCES;
        $list    = [];
        foreach ($sources as $key => $info) {
            $list[] = ['key' => $key, 'label' => $info['label'], 'url' => $info['url']];
        }
        $this->json($list);
    }

    public function openai(): void {
        $this->requireAuth();
        set_time_limit(600);

        $projetoId = (int) ($_POST['projeto_id'] ?? Auth::projetoId() ?? 0);
        if (!$projetoId) {
            $this->json(['error' => 'Projeto não selecionado.'], 400);
        }

        $pModel = new Projeto();
        if (!$pModel->canAccess($projetoId, Auth::id(), Auth::nivel(), Auth::clienteId())) {
            $this->json(['error' => 'Acesso negado.'], 403);
        }

        $apiKey = $pModel->getApiKey($projetoId);
        if (!$apiKey) {
            $this->json(['error' => 'Chave OpenAI não configurada para este projeto.'], 400);
        }

        $messages = $_POST['messages'] ?? '';
        if (!$messages) {
            $this->json(['error' => 'Mensagens inválidas.'], 400);
        }
        $decoded = json_decode($messages, true);
        if (!is_array($decoded)) {
            $this->json(['error' => 'Formato de mensagens inválido.'], 400);
        }

        $payload = json_encode([
            'model'       => 'gpt-4o-mini',
            'messages'    => $decoded,
            'temperature' => 0.3,
            'max_tokens'  => 16384,
        ]);

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ]),
            'content'        => $payload,
            'timeout'        => 540,
            'ignore_errors'  => true,
        ]]);

        $response = @file_get_contents('https://api.openai.com/v1/chat/completions', false, $ctx);

        if ($response === false) {
            $this->json(['error' => 'Falha ao conectar com a OpenAI.'], 502);
        }

        $data = json_decode($response, true);

        // Save to history
        $pergunta = '';
        foreach (array_reverse($decoded) as $m) {
            if ($m['role'] === 'user') { $pergunta = $m['content']; break; }
        }
        $resposta = $data['choices'][0]['message']['content'] ?? '';
        if ($pergunta && $resposta) {
            (new SentinelaConversa())->save($projetoId, Auth::id(), $pergunta, $resposta);
        }

        header('Content-Type: application/json; charset=utf-8');
        echo $response;
        exit;
    }

    public function agenteHistorico(): void {
        $this->requireAuth();

        $projetoId = (int)(Auth::projetoId() ?? 0);
        $usuarioId = (int)Auth::id();
        if (!$projetoId) { $this->json(['error' => 'Sem projeto'], 400); return; }

        $db         = Database::connect();
        $method     = $_SERVER['REQUEST_METHOD'];
        $contexto   = preg_replace('/[^a-z_]/', '', strtolower($_GET['contexto'] ?? 'sentinela'));
        $contextoId = isset($_GET['contexto_id']) ? substr((string)$_GET['contexto_id'], 0, 50) : '';

        if ($method === 'GET') {
            $st = $db->prepare(
                'SELECT historico FROM agente_historico
                 WHERE projeto_id=? AND usuario_id=? AND contexto=? AND contexto_id<=>?'
            );
            $st->execute([$projetoId, $usuarioId, $contexto, $contextoId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $this->json(['historico' => $row ? json_decode($row['historico'], true) : []]);
            return;
        }

        if ($method === 'POST') {
            $body     = json_decode(file_get_contents('php://input'), true) ?? [];
            $historico = $body['historico'] ?? [];
            if (!is_array($historico)) { $this->json(['error' => 'Inválido'], 400); return; }
            $db->prepare(
                'INSERT INTO agente_historico (projeto_id, usuario_id, contexto, contexto_id, historico)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE historico=VALUES(historico), atualizado_em=NOW()'
            )->execute([$projetoId, $usuarioId, $contexto, $contextoId, json_encode($historico)]);
            $this->json(['ok' => true]);
            return;
        }

        if ($method === 'DELETE') {
            $db->prepare(
                'DELETE FROM agente_historico
                 WHERE projeto_id=? AND usuario_id=? AND contexto=? AND contexto_id<=>?'
            )->execute([$projetoId, $usuarioId, $contexto, $contextoId]);
            $this->json(['ok' => true]);
            return;
        }

        $this->json(['error' => 'Método não suportado'], 405);
    }

    public function extras(): void {
        $this->requireAuth();
        session_write_close();

        $method = $_SERVER['REQUEST_METHOD'];
        $db     = Database::connect();

        // GET: lista extras para source+sapl_id+aba (todos os níveis podem ler)
        if ($method === 'GET') {
            $source  = $_GET['source']  ?? '';
            $saplId  = (int)($_GET['sapl_id'] ?? 0);
            $aba     = $_GET['aba'] ?? '';
            if (!$source || !$saplId || !$aba) {
                $this->json([], 200);
                return;
            }
            $st = $db->prepare(
                "SELECT id, titulo, dados_json, criado_em FROM parl_extras
                 WHERE source_key=? AND sapl_id=? AND aba=?
                 ORDER BY criado_em ASC"
            );
            $st->execute([$source, $saplId, $aba]);
            $rows = array_map(function ($r) {
                return [
                    'id'        => (int)$r['id'],
                    'titulo'    => $r['titulo'],
                    'dados'     => json_decode($r['dados_json'], true) ?? [],
                    'criado_em' => $r['criado_em'],
                ];
            }, $st->fetchAll(PDO::FETCH_ASSOC));
            $this->json($rows);
            return;
        }

        // Operações de escrita: apenas SuperAdmin
        if (!Auth::isSuperAdmin()) {
            $this->json(['error' => 'Acesso restrito'], 403);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // POST: cria nova entrada
        if ($method === 'POST') {
            $source  = trim($body['source_key'] ?? '');
            $saplId  = (int)($body['sapl_id'] ?? 0);
            $aba     = trim($body['aba'] ?? '');
            $titulo  = trim($body['titulo'] ?? '');
            $dados   = $body['dados'] ?? [];
            $abas    = ['inicio','materias','normas','emendas','comissoes','frentes','filiacoes','relatorias'];
            if (!$source || !$saplId || !in_array($aba, $abas)) {
                $this->json(['error' => 'Parâmetros inválidos'], 400);
                return;
            }
            $st = $db->prepare(
                "INSERT INTO parl_extras (source_key, sapl_id, aba, titulo, dados_json, criado_por)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $st->execute([$source, $saplId, $aba, $titulo, json_encode($dados, JSON_UNESCAPED_UNICODE), Auth::id()]);
            $this->json(['id' => (int)$db->lastInsertId(), 'ok' => true]);
            return;
        }

        // PUT: atualiza entrada existente
        if ($method === 'PUT') {
            $id     = (int)($body['id'] ?? 0);
            $titulo = trim($body['titulo'] ?? '');
            $dados  = $body['dados'] ?? [];
            if (!$id) { $this->json(['error' => 'ID inválido'], 400); return; }
            $db->prepare(
                "UPDATE parl_extras SET titulo=?, dados_json=? WHERE id=?"
            )->execute([$titulo, json_encode($dados, JSON_UNESCAPED_UNICODE), $id]);
            $this->json(['ok' => true]);
            return;
        }

        // DELETE: remove entrada
        if ($method === 'DELETE') {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) { $this->json(['error' => 'ID inválido'], 400); return; }
            $db->prepare("DELETE FROM parl_extras WHERE id=?")->execute([$id]);
            $this->json(['ok' => true]);
            return;
        }

        $this->json(['error' => 'Método não suportado'], 405);
    }
}
