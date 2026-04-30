<?php
class SaplApi {
    public static function baseUrl(string $source): string {
        $sources = SOURCES;
        return $sources[$source]['url'] ?? $sources[DEFAULT_SOURCE]['url'];
    }

    public static function get(string $path, string $source = DEFAULT_SOURCE, array $params = []): mixed {
        $raw = self::getRaw($path, $source, $params);
        return json_decode($raw, true);
    }

    public static function getRaw(string $path, string $source = DEFAULT_SOURCE, array $params = []): string {
        if ($source === 'camara_federal') {
            return self::getCamaraFederal($path, $params);
        }
        if ($source === 'senado') {
            return self::getSenado($path, $params);
        }

        $base = self::baseUrl($source);
        $url  = $base . '/api' . $path;
        if ($params) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'KeekConecta/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 429) {
            return '{"__rate_limited":true}';
        }

        if ($body === false || $code < 200 || $code >= 300) {
            return '{}';
        }

        $trimmed = ltrim($body);
        if (str_starts_with($trimmed, '<')) {
            return '{}';
        }

        return $body;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Adaptador Câmara Federal
    // A API de dados abertos da Câmara (dadosabertos.camara.leg.br/api/v2/)
    // tem estrutura diferente da SAPL. Este método traduz os paths SAPL para os
    // paths corretos e normaliza a resposta para o formato esperado pelo app.js.
    // ─────────────────────────────────────────────────────────────────────────
    private static function getCamaraFederal(string $path, array $params): string {
        $empty = json_encode([
            'results'    => [],
            'pagination' => ['total_pages' => 1, 'total_entries' => 0, 'links' => new stdClass()],
        ]);

        // Params embutidos no path (?parlamentar=X&...) têm precedência
        $parsed     = parse_url($path);
        $cleanPath  = $parsed['path'] ?? $path;
        $pathParams = [];
        if (!empty($parsed['query'])) parse_str($parsed['query'], $pathParams);
        $params = array_merge($pathParams, $params);

        $page         = max(1, (int)($params['page'] ?? 1));
        $camaraParams = ['pagina' => $page, 'itens' => 100];
        $normalizeAs  = null;

        if (str_contains($cleanPath, '/parlamentares/parlamentar')) {
            $camaraPath  = '/api/v2/deputados';
            $normalizeAs = 'deputado';

        } elseif (str_contains($cleanPath, '/parlamentares/legislatura')) {
            $camaraPath  = '/api/v2/legislaturas';
            $camaraParams['itens'] = 50;
            $normalizeAs = 'legislatura';

        } elseif (str_contains($cleanPath, '/parlamentares/partido')) {
            $camaraPath  = '/api/v2/partidos';
            $normalizeAs = 'partido';

        } elseif (str_contains($cleanPath, '/parlamentares/mandato')) {
            $parlId = $params['parlamentar'] ?? '';
            if ($parlId) {
                // Mandatos de um deputado específico — busca detalhes dele
                $camaraPath   = '/api/v2/deputados/' . (int)$parlId;
                $camaraParams = [];
                $normalizeAs  = 'mandato_deputado';
            } else {
                $camaraPath = '/api/v2/deputados';
                $leg = $params['legislatura'] ?? '';
                if ($leg) $camaraParams['idLegislatura'] = $leg;
                $normalizeAs = 'mandato';
            }

        } elseif (str_contains($cleanPath, '/materia/materialegislativa')) {
            if (preg_match('#/(\d+)/?$#', $cleanPath, $dm)) {
                // Detalhe de proposição — retorna objeto flat (sem wrapper results)
                return self::getCamaraProposicaoDetalhe((int)$dm[1]);
            }
            $autorId = $params['autoria__autor'] ?? '';
            if (!$autorId) return $empty;
            $camaraPath  = '/api/v2/proposicoes';
            $camaraParams = ['idDeputadoAutor' => (int)$autorId, 'pagina' => $page, 'itens' => 100];
            $normalizeAs  = 'proposicao_mat';

        } elseif (str_contains($cleanPath, '/materia/tramitacao')) {
            $materiaId = $params['materia'] ?? '';
            if (!$materiaId) return $empty;
            $camaraPath  = '/api/v2/proposicoes/' . (int)$materiaId . '/tramitacoes';
            $camaraParams = [];
            $normalizeAs  = 'tramitacao';

        } elseif (str_contains($cleanPath, '/materia/autoria')) {
            $autorId   = $params['autor']   ?? '';
            $materiaId = $params['materia'] ?? '';
            if ($materiaId) {
                $camaraPath  = '/api/v2/proposicoes/' . (int)$materiaId . '/autores';
                $camaraParams = [];
                $normalizeAs  = 'autores_proposicao';
            } elseif ($autorId) {
                $camaraPath  = '/api/v2/proposicoes';
                $camaraParams = ['idDeputadoAutor' => (int)$autorId, 'pagina' => $page, 'itens' => 100];
                $normalizeAs  = 'proposicao';
            } else {
                return $empty;
            }

        } elseif (str_contains($cleanPath, '/comissoes/participacao')) {
            $parlId = $params['parlamentar'] ?? '';
            if (!$parlId) return $empty;
            $camaraPath  = '/api/v2/deputados/' . (int)$parlId . '/orgaos';
            $camaraParams = ['pagina' => $page, 'itens' => 100];
            $normalizeAs  = 'orgao';

        } elseif (str_contains($cleanPath, '/parlamentares/filiacao')) {
            $parlId = $params['parlamentar'] ?? '';
            if (!$parlId) return $empty;
            $camaraPath  = '/api/v2/deputados/' . (int)$parlId;
            $camaraParams = [];
            $normalizeAs  = 'filiacao_deputado';

        } elseif (str_contains($cleanPath, '/parlamentares/frenteparlamentar')) {
            $parlId = $params['parlamentar'] ?? '';
            if (!$parlId) return $empty;
            $camaraPath   = '/api/v2/deputados/' . (int)$parlId . '/frentes';
            $camaraParams = [];
            $normalizeAs  = 'frente_deputado';

        } elseif (str_contains($cleanPath, '/parlamentares/perfil')) {
            $parlId = $params['parlamentar'] ?? '';
            if (!$parlId) return $empty;
            $camaraPath   = '/api/v2/deputados/' . (int)$parlId;
            $camaraParams = [];
            $normalizeAs  = 'deputado_detalhe';

        } else {
            return $empty;
        }

        $url  = 'https://dadosabertos.camara.leg.br' . $camaraPath;
        $url .= '?' . http_build_query($camaraParams);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'KeekConecta/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$body || $code < 200 || $code >= 300) return $empty;
        if (str_starts_with(ltrim($body), '<'))     return $empty;

        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['dados'])) return $empty;

        $dados = $data['dados'] ?? [];
        $links = $data['links'] ?? [];

        // Extrai próxima página e total de páginas dos links
        $nextHref = null;
        $lastPage = $page;
        foreach ($links as $link) {
            $rel  = $link['rel']  ?? '';
            $href = $link['href'] ?? '';
            if ($rel === 'next') {
                $nextHref = $href;
            }
            if ($rel === 'last') {
                $q = [];
                parse_str(parse_url($href, PHP_URL_QUERY) ?: '', $q);
                if (!empty($q['pagina'])) {
                    $lastPage = max($lastPage, (int)$q['pagina']);
                }
            }
        }

        $legId = $camaraParams['idLegislatura'] ?? ($params['legislatura'] ?? null);

        switch ($normalizeAs) {
            case 'deputado':
                $results = array_map(function ($d) {
                    return [
                        'id'               => $d['id']            ?? null,
                        'nome_completo'    => $d['nome']          ?? '',
                        'nome_parlamentar' => $d['nome']          ?? '',
                        'partido'          => ['sigla' => $d['siglaPartido'] ?? ''],
                        'uf'               => $d['siglaUf']       ?? '',
                        'fotografia'       => $d['urlFoto']       ?? '',
                        'email'            => $d['email']         ?? '',
                        'ativo'            => true,
                    ];
                }, $dados);
                break;

            case 'legislatura':
                $results = array_map(function ($d) {
                    return [
                        'id'          => $d['id']          ?? null,
                        'numero'      => $d['id']          ?? null, // id == número da legislatura
                        'data_inicio' => $d['dataInicio']  ?? '',
                        'data_fim'    => $d['dataFim']     ?? '',
                    ];
                }, $dados);
                break;

            case 'partido':
                $results = array_map(function ($d) {
                    return [
                        'id'   => $d['id']   ?? null,
                        'sigla'=> $d['sigla'] ?? '',
                        'nome' => $d['nome']  ?? '',
                    ];
                }, $dados);
                break;

            case 'mandato':
                $results = array_map(function ($d) use ($legId) {
                    return [
                        'parlamentar' => $d['id'] ?? null,
                        'titular'     => true,
                        'legislatura' => $legId ? (int)$legId : null,
                    ];
                }, $dados);
                break;

            case 'proposicao_mat':
                $results = array_map(function ($d) {
                    $tipo   = $d['siglaTipo'] ?? '';
                    $num    = $d['numero']    ?? '';
                    $ano    = $d['ano']       ?? '';
                    $ementa = trim($d['ementa'] ?? '');
                    return [
                        'id'      => $d['id'] ?? null,
                        'tipo'    => ['sigla' => $tipo, 'descricao' => $tipo],
                        'numero'  => $num,
                        'ano'     => (int)$ano,
                        'ementa'  => $ementa,
                        '__str__' => trim("{$tipo} nº {$num}/{$ano}"),
                    ];
                }, $dados);
                break;

            case 'proposicao':
                $results = array_map(function ($d) {
                    $tipo   = $d['siglaTipo'] ?? '';
                    $num    = $d['numero']    ?? '';
                    $ano    = $d['ano']       ?? '';
                    $ementa = trim($d['ementa'] ?? '');
                    $str    = trim("{$tipo} nº {$num}/{$ano}" . ($ementa ? " - {$ementa}" : ''));
                    return [
                        '__str__'       => $str,
                        'materia'       => $d['id']  ?? null,
                        'primeiro_autor'=> true,
                        'ano'           => (int)$ano,
                    ];
                }, $dados);
                break;

            case 'orgao':
                $results = array_map(function ($d) {
                    $nome   = $d['nomeOrgao'] ?? ($d['siglaOrgao'] ?? '');
                    $titulo = $d['titulo']    ?? '';
                    $str    = $nome . ($titulo ? ' - ' . $titulo : '');
                    return [
                        '__str__'           => $str,
                        'data_designacao'   => $d['dataInicio'] ?? null,
                        'data_desligamento' => $d['dataFim']    ?? null,
                        'titular'           => strtolower($titulo) === 'titular',
                    ];
                }, $dados);
                break;

            case 'mandato_deputado':
                // /api/v2/deputados/{id} retorna objeto único
                $dep    = is_array($dados) && !isset($dados[0]) ? $dados : [];
                $status = $dep['ultimoStatus'] ?? [];
                $idLeg  = $status['idLegislatura'] ?? null;
                $results = $idLeg ? [[
                    'parlamentar'     => (int)($dep['id'] ?? 0),
                    'titular'         => true,
                    'legislatura'     => (int)$idLeg,
                    'votos_recebidos' => null,
                    'coligacao'       => null,
                ]] : [];
                break;

            case 'filiacao_deputado':
                $dep    = is_array($dados) && !isset($dados[0]) ? $dados : [];
                $status = $dep['ultimoStatus'] ?? [];
                $sigla  = $status['siglaPartido'] ?? '';
                $results = $sigla ? [[
                    '__str__'          => $sigla,
                    'partido'          => $sigla,
                    'data'             => $status['data'] ?? null,
                    'data_desfiliacao' => null,
                ]] : [];
                break;

            case 'frente_deputado':
                $results = array_map(function ($f) {
                    return [
                        'frente'      => $f['id']     ?? null,
                        '__str__'     => $f['titulo'] ?? '',
                        'cargo'       => null,
                        'data_entrada'=> null,
                        'data_saida'  => null,
                    ];
                }, $dados);
                break;

            case 'deputado_detalhe':
                $dep    = is_array($dados) && !isset($dados[0]) ? $dados : [];
                $status = $dep['ultimoStatus'] ?? [];
                $results = [[
                    'dataNascimento'     => $dep['dataNascimento']     ?? null,
                    'municipioNascimento'=> $dep['municipioNascimento'] ?? null,
                    'ufNascimento'       => $dep['ufNascimento']        ?? null,
                    'escolaridade'       => $dep['escolaridade']        ?? null,
                    'redeSocial'         => $dep['redeSocial']          ?? [],
                    'sitePessoal'        => $dep['sitePessoal']         ?? null,
                    'condicaoEleitoral'  => $status['condicaoEleitoral'] ?? null,
                    'descricaoStatus'    => $status['descricaoStatus']   ?? null,
                ]];
                break;

            case 'tramitacao':
                $results = array_map(function ($t) {
                    $orgao  = $t['siglaOrgao']          ?? '';
                    $sit    = $t['descricaoSituacao']   ?? '';
                    $desp   = $t['despacho']            ?? '';
                    return [
                        'data_tramitacao'                  => substr($t['dataHora'] ?? '', 0, 10),
                        '__str__'                          => trim($orgao . ($sit ? ' — ' . $sit : '')),
                        'unidade_tramitacao_destino'       => ['nome' => $orgao],
                        'status'                           => ['descricao' => $sit],
                        'texto'                            => $desp,
                    ];
                }, $dados);
                break;

            case 'autores_proposicao':
                $results = array_map(function ($a) {
                    return [
                        '__str__'       => $a['nome'] ?? '',
                        'primeiro_autor'=> (int)($a['ordemAssinatura'] ?? 1) === 1,
                        'materia'       => null,
                    ];
                }, $dados);
                break;

            default:
                $results = $dados;
        }

        return json_encode([
            'results'    => $results,
            'pagination' => [
                'total_pages'   => $lastPage,
                'total_entries' => count($dados) * $lastPage,
                'links'         => $nextHref ? ['next' => $nextHref] : new stdClass(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Câmara Federal: detalhe de proposição (retorna objeto flat, não {results})
    // ─────────────────────────────────────────────────────────────────────────
    private static function getCamaraProposicaoDetalhe(int $id): string {
        $url = 'https://dadosabertos.camara.leg.br/api/v2/proposicoes/' . $id;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT      => 'KeekConecta/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$body || $code < 200 || $code >= 300) return '{}';
        $data = json_decode($body, true);
        $d    = $data['dados'] ?? [];
        if (!$d) return '{}';

        $tipo  = $d['siglaTipo'] ?? '';
        $num   = $d['numero']    ?? '';
        $ano   = $d['ano']       ?? '';
        return json_encode([
            'id'               => $d['id']            ?? $id,
            '__str__'          => trim("{$tipo} nº {$num}/{$ano}"),
            'tipo'             => $tipo,
            'numero'           => $num,
            'ano'              => (int)$ano,
            'data_apresentacao'=> substr($d['dataApresentacao'] ?? '', 0, 10),
            'ementa'           => $d['ementa']        ?? '',
            'em_tramitacao'    => !empty($d['statusProposicao']),
            'texto_original'   => $d['urlTeorPDF']    ?? null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Adaptador Senado Federal
    // API: https://legis.senado.leg.br/dadosabertos/
    // ─────────────────────────────────────────────────────────────────────────
    private static function getSenado(string $path, array $params): string {
        $empty = json_encode([
            'results'    => [],
            'pagination' => ['total_pages' => 1, 'total_entries' => 0, 'links' => new stdClass()],
        ]);

        $parsed    = parse_url($path);
        $cleanPath = $parsed['path'] ?? $path;
        $pathParams = [];
        if (!empty($parsed['query'])) parse_str($parsed['query'], $pathParams);
        $params = array_merge($pathParams, $params);

        $base    = 'https://legis.senado.leg.br';
        $headers = ['Accept: application/json', 'User-Agent: KeekConecta/1.0'];

        $fetch = function (string $url) use ($headers): ?array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!$body || $code < 200 || $code >= 300) return null;
            $d = json_decode($body, true);
            return is_array($d) ? $d : null;
        };

        // Garante que campo seja sempre array (Senado retorna objeto quando há 1 item)
        $toArray = function (mixed $v): array {
            if (!$v) return [];
            return isset($v[0]) || $v === [] ? $v : [$v];
        };

        $wrap = function (array $results) {
            return json_encode([
                'results'    => $results,
                'pagination' => ['total_pages' => 1, 'total_entries' => count($results), 'links' => new stdClass()],
            ]);
        };

        // ── Lista de senadores em exercício ──────────────────────────────────
        if (str_contains($cleanPath, '/parlamentares/parlamentar')) {
            $data = $fetch($base . '/dadosabertos/senador/lista/atual');
            if (!$data) return $empty;
            $list = $toArray($data['ListaParlamentarEmExercicio']['Parlamentares']['Parlamentar'] ?? []);
            $results = array_map(function ($p) {
                $id = $p['IdentificacaoParlamentar'] ?? [];
                return [
                    'id'               => (int)($id['CodigoParlamentar']      ?? 0),
                    'nome_parlamentar' => $id['NomeParlamentar']               ?? '',
                    'nome_completo'    => $id['NomeCompletoParlamentar']       ?? '',
                    'fotografia'       => $id['UrlFotoParlamentar']            ?? '',
                    'email'            => $id['EmailParlamentar']              ?? '',
                    'partido'          => ['sigla' => $id['SiglaPartidoParlamentar'] ?? ''],
                    'uf'               => $id['UfParlamentar']                 ?? '',
                    'ativo'            => true,
                ];
            }, $list);
            return $wrap($results);

        // ── Legislaturas ─────────────────────────────────────────────────────
        // O endpoint /dadosabertos/legislatura/lista retorna 404; usamos lista fixa.
        } elseif (str_contains($cleanPath, '/parlamentares/legislatura')) {
            $results = [
                ['id' => 57, 'numero' => 57, 'data_inicio' => '2023-02-01', 'data_fim' => '2027-01-31'],
                ['id' => 56, 'numero' => 56, 'data_inicio' => '2019-02-01', 'data_fim' => '2023-01-31'],
                ['id' => 55, 'numero' => 55, 'data_inicio' => '2015-02-01', 'data_fim' => '2019-01-31'],
                ['id' => 54, 'numero' => 54, 'data_inicio' => '2011-02-01', 'data_fim' => '2015-01-31'],
                ['id' => 53, 'numero' => 53, 'data_inicio' => '2007-02-01', 'data_fim' => '2011-01-31'],
            ];
            return $wrap($results);

        // ── Partidos (derivados da lista atual) ──────────────────────────────
        } elseif (str_contains($cleanPath, '/parlamentares/partido')) {
            $data = $fetch($base . '/dadosabertos/senador/lista/atual');
            if (!$data) return $empty;
            $list   = $toArray($data['ListaParlamentarEmExercicio']['Parlamentares']['Parlamentar'] ?? []);
            $siglas = [];
            foreach ($list as $p) {
                $sigla = $p['IdentificacaoParlamentar']['SiglaPartidoParlamentar'] ?? '';
                if ($sigla && !isset($siglas[$sigla])) {
                    $siglas[$sigla] = ['id' => $sigla, 'sigla' => $sigla, 'nome' => $sigla];
                }
            }
            $results = array_values($siglas);
            usort($results, fn ($a, $b) => strcmp($a['sigla'], $b['sigla']));
            return $wrap($results);

        // ── Mandatos ─────────────────────────────────────────────────────────
        } elseif (str_contains($cleanPath, '/parlamentares/mandato')) {
            $parlId = $params['parlamentar'] ?? '';
            $legNum = $params['legislatura'] ?? '';

            if ($parlId) {
                // Mandatos de um senador específico
                $data = $fetch($base . '/dadosabertos/senador/' . (int)$parlId . '/mandatos');
                if (!$data) return $empty;
                $list = $toArray($data['MandatoParlamentar']['Parlamentar']['Mandatos']['Mandato'] ?? []);
                $results = [];
                foreach ($list as $m) {
                    $leg1 = (int)($m['PrimeiraLegislaturaDoMandato']['NumeroLegislatura'] ?? 0);
                    $leg2 = (int)($m['SegundaLegislaturaDoMandato']['NumeroLegislatura']  ?? 0);
                    if ($leg1) $results[] = ['parlamentar' => (int)$parlId, 'legislatura' => $leg1, 'titular' => true, 'votos_recebidos' => null, 'coligacao' => null];
                    if ($leg2) $results[] = ['parlamentar' => (int)$parlId, 'legislatura' => $leg2, 'titular' => true, 'votos_recebidos' => null, 'coligacao' => null];
                }
                usort($results, fn ($a, $b) => $b['legislatura'] - $a['legislatura']);
                return $wrap($results);
            }

            if ($legNum) {
                // Senadores de uma legislatura (para filtro de lista)
                $data = $fetch($base . '/dadosabertos/senador/lista/legislatura/' . (int)$legNum);
                if (!$data) return $empty;
                $list = $toArray($data['ListaParlamentarLegislatura']['Parlamentares']['Parlamentar'] ?? []);
                $results = array_map(function ($p) use ($legNum) {
                    $id   = $p['IdentificacaoParlamentar'] ?? [];
                    $desc = strtolower($id['DescricaoParticipacao'] ?? 'titular');
                    return [
                        'parlamentar' => (int)($id['CodigoParlamentar'] ?? 0),
                        'legislatura' => (int)$legNum,
                        'titular'     => str_contains($desc, 'titular'),
                    ];
                }, $list);
                return $wrap($results);
            }

            return $empty;

        // ── Matérias (autorias) ───────────────────────────────────────────────
        } elseif (str_contains($cleanPath, '/materia/autoria')) {
            $autorId = $params['autor'] ?? '';
            if (!$autorId) return $empty;
            $data = $fetch($base . '/dadosabertos/senador/' . (int)$autorId . '/autorias');
            if (!$data) return $empty;
            $list = $toArray($data['MateriasAutoriaParlamentar']['Parlamentar']['Autorias']['Autoria'] ?? []);
            $results = array_map(function ($a) {
                $m         = $a['Materia'] ?? [];
                $desc      = trim($m['DescricaoIdentificacao'] ?? '');
                $ementa    = trim($m['Ementa'] ?? '');
                $principal = strtolower($a['IndicadorAutorPrincipal'] ?? 'Sim') !== 'não';
                $str       = $desc . ($ementa ? ' — ' . mb_substr($ementa, 0, 120) : '');
                return [
                    '__str__'       => $str,
                    'materia'       => (int)($m['Codigo'] ?? 0),
                    'primeiro_autor'=> $principal,
                    'ano'           => (int)($m['Ano'] ?? substr($desc, -4)),
                ];
            }, $list);
            return $wrap($results);

        // ── Comissões ────────────────────────────────────────────────────────
        } elseif (str_contains($cleanPath, '/comissoes/participacao')) {
            $parlId = $params['parlamentar'] ?? '';
            if (!$parlId) return $empty;
            $data = $fetch($base . '/dadosabertos/senador/' . (int)$parlId . '/comissoes');
            if (!$data) return $empty;
            $list = $toArray($data['MembroComissaoParlamentar']['Parlamentar']['MembroComissoes']['Comissao'] ?? []);
            $results = array_map(function ($c) {
                $ident = $c['IdentificacaoComissao']  ?? [];
                $sigla = $ident['SiglaComissao']       ?? '';
                $nome  = $ident['NomeComissao']         ?? $sigla;
                $cargo = $c['DescricaoParticipacao']   ?? '';
                $str   = ($sigla ? $sigla . ' — ' : '') . $nome . ($cargo ? ' - ' . $cargo : '');
                $desc  = strtolower($cargo);
                return [
                    '__str__'           => $str,
                    'data_designacao'   => $c['DataInicio'] ?? '',
                    'data_desligamento' => $c['DataFim']    ?? null,
                    'titular'           => str_contains($desc, 'titular'),
                ];
            }, $list);
            return $wrap($results);

        // ── Relatorias ────────────────────────────────────────────────────────
        } elseif (str_contains($cleanPath, '/materia/relatoria')) {
            $parlId = $params['parlamentar'] ?? '';
            if (!$parlId) return $empty;
            $data = $fetch($base . '/dadosabertos/senador/' . (int)$parlId . '/relatorias');
            if (!$data) return $empty;
            $list = $toArray($data['MateriasRelatoriaParlamentar']['Parlamentar']['Relatorias']['Relatoria'] ?? []);
            $results = array_map(function ($r) {
                $mat    = $r['Materia']  ?? [];
                $com    = $r['Comissao'] ?? [];
                $desc   = trim($mat['DescricaoIdentificacao'] ?? '');
                $ementa = trim($mat['Ementa'] ?? '');
                $str    = $desc . ($ementa ? ' — ' . mb_substr($ementa, 0, 100) : '');
                $siglaC = $com['Sigla'] ?? '';
                $nomeC  = $com['Nome']  ?? $siglaC;
                return [
                    '__str__'               => $str ?: ('Relatoria #' . ($mat['Codigo'] ?? '')),
                    'materia'               => (int)($mat['Codigo'] ?? 0) ?: null,
                    'comissao'              => ['__str__' => ($siglaC ? $siglaC . ' — ' : '') . $nomeC],
                    'data_designacao_relator'  => $r['DataDesignacao'] ?? null,
                    'data_destituicao_relator' => null,
                ];
            }, $list);
            return $wrap($results);

        // ── Filiações partidárias ─────────────────────────────────────────────
        } elseif (str_contains($cleanPath, '/parlamentares/filiacao')) {
            $parlId = $params['parlamentar'] ?? '';
            if (!$parlId) return $empty;
            $data = $fetch($base . '/dadosabertos/senador/' . (int)$parlId . '/filiacoes');
            if (!$data) return $empty;
            $list = $toArray($data['FiliacaoParlamentar']['Parlamentar']['Filiacoes']['Filiacao'] ?? []);
            $results = array_map(function ($f) {
                $partido = $f['Partido'] ?? [];
                $sigla   = $partido['SiglaPartido'] ?? '';
                $desfil  = $f['DataDesfiliacao'] ?? null;
                return [
                    '__str__'          => $sigla,
                    'partido'          => $sigla,
                    'data'             => $f['DataFiliacao'] ?? '',
                    'data_desfiliacao' => $desfil ?: null,
                ];
            }, $list);
            return $wrap($results);

        } else {
            return $empty;
        }
    }

    private static function convertBrDate(string $date): string {
        if (!$date) return '';
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return $date;
    }
}
