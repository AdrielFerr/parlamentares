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
            CURLOPT_TIMEOUT        => defined('SAPL_CURL_TIMEOUT') ? SAPL_CURL_TIMEOUT : 15,
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

        } elseif (preg_match('#/norma/normajuridica/(\d+)/#', $cleanPath, $dm)) {
            return self::getCamaraProposicaoDetalhe((int)$dm[1]);

        } elseif (str_contains($cleanPath, '/norma/')) {
            $autorId = $params['autor'] ?? $params['parlamentar'] ?? '';
            if (!$autorId) return $empty;
            $camaraPath   = '/api/v2/proposicoes';
            $camaraParams = [
                'idDeputadoAutor' => (int)$autorId,
                'codSituacao'     => 1140, // Transformado em Norma Jurídica
                'pagina'          => $page,
                'itens'           => 100,
                'ordem'           => 'DESC',
                'ordenarPor'      => 'id',
            ];
            $normalizeAs  = 'norma_camara';

        } elseif (str_contains($cleanPath, '/emendas/')) {
            $parlId = $params['parlamentar'] ?? '';
            $ano    = (int)($params['ano'] ?? date('Y'));
            if (!$parlId) return $empty;
            $camaraPath   = '/api/v2/emendas';
            $camaraParams = [
                'idAutor'    => (int)$parlId,
                'ano'        => $ano,
                'pagina'     => $page,
                'itens'      => 100,
                'ordem'      => 'DESC',
                'ordenarPor' => 'valorDotacaoAtual',
            ];
            $normalizeAs  = 'emenda_camara';

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
                        'id'               => $d['id'] ?? null,
                        'tipo'             => ['sigla' => $tipo, 'descricao' => $tipo],
                        'numero'           => $num,
                        'ano'              => (int)$ano,
                        'ementa'           => $ementa,
                        'data_apresentacao'=> substr($d['dataApresentacao'] ?? '', 0, 10),
                        '__str__'          => trim("{$tipo} nº {$num}/{$ano}"),
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
                        'tipo_sigla'    => $tipo,
                        'numero'        => $num,
                        'ementa'        => $ementa,
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

            case 'norma_camara':
                $results = array_map(function ($p) {
                    $tipo = $p['siglaTipo'] ?? '';
                    $num  = $p['numero']    ?? '';
                    $ano  = $p['ano']       ?? '';
                    return [
                        '__str__'        => trim("{$tipo} nº {$num}/{$ano}"),
                        'norma'          => (int)($p['id'] ?? 0) ?: null,
                        'primeiro_autor' => true,
                        'tipo'           => $tipo,
                        'numero'         => (string)$num,
                        'ano'            => (int)$ano ?: null,
                        'ementa'         => $p['ementa'] ?? '',
                        'data'           => substr($p['dataApresentacao'] ?? '', 0, 10),
                        'texto_integral' => null,
                    ];
                }, $dados);
                break;

            case 'emenda_camara':
                $results = array_map(function ($d) {
                    $tipo    = $d['tipoEmenda']         ?? '';
                    $num     = $d['numeroEmenda']        ?? $d['codEmenda'] ?? '';
                    $ano     = $d['ano']                 ?? '';
                    $loc     = $d['localidadeGasto']     ?? '';
                    $func    = $d['descFuncao']          ?? $d['funcao']    ?? '';
                    $subfunc = $d['descSubfuncao']       ?? $d['subfuncao'] ?? '';
                    $vDot    = (float)($d['valorDotacaoAtual'] ?? 0);
                    $vEmp    = (float)($d['valorEmpenhado']    ?? 0);
                    $vPag    = (float)($d['valorPago']         ?? 0);
                    $str     = trim("{$tipo} nº {$num}/{$ano}" . ($loc ? " — {$loc}" : ''));
                    return [
                        '__str__'         => $str,
                        'id'              => (string)$num,
                        'tipo'            => $tipo,
                        'numero'          => (string)$num,
                        'ano'             => (int)$ano ?: null,
                        'localidade'      => $loc,
                        'funcao'          => $func,
                        'subfuncao'       => $subfunc,
                        'valor_dotacao'   => $vDot,
                        'valor_empenhado' => $vEmp,
                        'valor_pago'      => $vPag,
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

        $tipo    = $d['siglaTipo']     ?? '';
        $num     = $d['numero']        ?? '';
        $ano     = $d['ano']           ?? '';
        $dataStr = substr($d['dataApresentacao'] ?? '', 0, 10);
        $pdfUrl  = $d['urlTeorPDF']    ?? null;
        $status  = $d['statusProposicao'] ?? [];
        $situacao    = $status['descricaoSituacao'] ?? '';
        $orgaoAtual  = $status['siglaOrgao']        ?? '';
        $regime      = $status['regime']            ?? '';
        $despacho    = $status['despacho']          ?? '';
        $keywords    = $d['keywords']               ?? '';
        $descTipo    = $d['descricaoTipo']          ?? '';
        $emTramit    = !empty($status) && !in_array(strtolower($situacao), [
            'transformado em norma jurídica', 'arquivada', 'retirada pelo autor',
            'prejudicada', 'rejeitada', 'vetada integralmente',
        ]);
        return json_encode([
            'id'               => $d['id']      ?? $id,
            '__str__'          => trim("{$tipo} nº {$num}/{$ano}"),
            'tipo'             => ['sigla' => $tipo, 'descricao' => $descTipo ?: $tipo],
            'numero'           => $num,
            'ano'              => (int)$ano,
            'data_apresentacao'=> $dataStr,
            'data'             => $dataStr,
            'ementa'           => $d['ementa']  ?? '',
            'em_tramitacao'    => $emTramit,
            'situacao'         => $situacao,
            'orgao_atual'      => $orgaoAtual,
            'regime_tramitacao'=> $regime ? ['id' => 0, 'descricao' => $regime] : null,
            'despacho_atual'   => $despacho,
            'palavras_chave'   => $keywords,
            'texto_original'   => $pdfUrl,
            'texto_integral'   => $pdfUrl,
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

        // ── Perfil individual do senador ──────────────────────────────────────
        if (str_contains($cleanPath, '/parlamentares/perfil')) {
            $parlId = $params['parlamentar'] ?? '';
            if (!$parlId) return $empty;
            $data = $fetch($base . '/dadosabertos/senador/' . (int)$parlId);
            $parl = $data['DetalheParlamentar']['Parlamentar'] ?? [];
            $idn  = $parl['IdentificacaoParlamentar']   ?? [];
            $dbs  = $parl['DadosBasicosParlamentar']    ?? [];
            if (!$idn) return $empty;

            // Profissões: endpoint separado /profissao
            $profData  = $fetch($base . '/dadosabertos/senador/' . (int)$parlId . '/profissao');
            $profList  = $profData['HistoricoAcademicoParlamentar']['Parlamentar']['Profissoes']['Profissao'] ?? [];
            if (!empty($profList) && !isset($profList[0])) $profList = [$profList];
            $profissao = implode(', ', array_column($profList, 'NomeProfissao')) ?: null;

            return $wrap([[
                'id'                  => (int)$parlId,
                'condicaoEleitoral'   => 'Titular',
                'biografia'           => null,
                'dataNascimento'      => substr($dbs['DataNascimento']         ?? '', 0, 10) ?: null,
                'municipioNascimento' => $dbs['Naturalidade']                  ?? null,
                'ufNascimento'        => $dbs['UfNaturalidade']                ?? null,
                'escolaridade'        => null,
                'profissao'           => $profissao,
                'sitePessoal'         => $idn['UrlPaginaParlamentar']          ?? null,
                'gabinete'            => null,
                'telefone'            => null,
            ]]);

        // ── Lista de senadores em exercício ──────────────────────────────────
        } elseif (str_contains($cleanPath, '/parlamentares/parlamentar')) {
            $data = $fetch($base . '/dadosabertos/senador/lista/atual');
            if (!$data) return $empty;
            $list = $toArray($data['ListaParlamentarEmExercicio']['Parlamentares']['Parlamentar'] ?? []);

            // Busca a leg. atual para determinar titular vs suplente
            // DescricaoParticipacao fica em Mandatos.Mandato[N], não em IdentificacaoParlamentar
            $legAtual = $fetch($base . '/dadosabertos/senador/lista/legislatura/57');
            $legList  = $toArray($legAtual['ListaParlamentarLegislatura']['Parlamentares']['Parlamentar'] ?? []);
            $titularMap = [];
            foreach ($legList as $lp) {
                $lid      = $lp['IdentificacaoParlamentar'] ?? [];
                $mandatos = $lp['Mandatos']['Mandato']     ?? [];
                if ($mandatos && !isset($mandatos[0])) $mandatos = [$mandatos];
                $mandato  = $mandatos[0] ?? [];
                $desc     = strtolower($mandato['DescricaoParticipacao'] ?? '');
                $cod      = (int)($lid['CodigoParlamentar'] ?? 0);
                if ($cod) $titularMap[$cod] = ($desc === '' || str_contains($desc, 'titular'));
            }

            $results = array_map(function ($p) use ($titularMap) {
                $id  = $p['IdentificacaoParlamentar'] ?? [];
                $cod = (int)($id['CodigoParlamentar'] ?? 0);
                return [
                    'id'               => $cod,
                    'nome_parlamentar' => $id['NomeParlamentar']               ?? '',
                    'nome_completo'    => $id['NomeCompletoParlamentar']       ?? '',
                    'fotografia'       => $id['UrlFotoParlamentar']            ?? '',
                    'email'            => $id['EmailParlamentar']              ?? '',
                    'partido'          => ['sigla' => $id['SiglaPartidoParlamentar'] ?? ''],
                    'uf'               => $id['UfParlamentar']                 ?? '',
                    'ativo'            => true,
                    'titular'          => $titularMap[$cod] ?? true,
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
                // Senadores de uma legislatura — DescricaoParticipacao está em Mandatos.Mandato[N]
                $data = $fetch($base . '/dadosabertos/senador/lista/legislatura/' . (int)$legNum);
                if (!$data) return $empty;
                $list = $toArray($data['ListaParlamentarLegislatura']['Parlamentares']['Parlamentar'] ?? []);
                $results = array_map(function ($p) use ($legNum) {
                    $id       = $p['IdentificacaoParlamentar'] ?? [];
                    $mandatos = $p['Mandatos']['Mandato']     ?? [];
                    if ($mandatos && !isset($mandatos[0])) $mandatos = [$mandatos];
                    // Encontra o mandato correspondente à legislatura pedida
                    $mandato = null;
                    foreach ($mandatos as $m) {
                        $l1 = (int)($m['PrimeiraLegislaturaDoMandato']['NumeroLegislatura'] ?? 0);
                        $l2 = (int)($m['SegundaLegislaturaDoMandato']['NumeroLegislatura']  ?? 0);
                        if ($l1 === (int)$legNum || $l2 === (int)$legNum) { $mandato = $m; break; }
                    }
                    $mandato = $mandato ?? ($mandatos[0] ?? []);
                    $desc    = strtolower($mandato['DescricaoParticipacao'] ?? '');
                    return [
                        'parlamentar' => (int)($id['CodigoParlamentar'] ?? 0),
                        'legislatura' => (int)$legNum,
                        'titular'     => ($desc === '' || str_contains($desc, 'titular')),
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

        // ── Normas / Proposições aprovadas (processos encerrados) ────────────
        } elseif (str_contains($cleanPath, '/norma/')) {
            $parlId = $params['autor'] ?? $params['parlamentar'] ?? '';
            $anoFiltro = (int)($params['ano'] ?? 0);

            if (str_contains($cleanPath, '/tiponorma')) {
                return $wrap([
                    ['id' => 1, 'sigla' => 'PL',  'descricao' => 'Projeto de Lei'],
                    ['id' => 2, 'sigla' => 'PLC', 'descricao' => 'Projeto de Lei Complementar'],
                    ['id' => 3, 'sigla' => 'PEC', 'descricao' => 'Proposta de Emenda à Constituição'],
                    ['id' => 4, 'sigla' => 'PDL', 'descricao' => 'Projeto de Decreto Legislativo'],
                    ['id' => 5, 'sigla' => 'PRS', 'descricao' => 'Projeto de Resolução'],
                ]);
            }

            if (!$parlId) return $empty;

            // Usa senador/{id}/autorias — único endpoint que filtra corretamente por senador.
            // pesquisa/lista e dadosabertos/processo ignoram codigoParlamentar/codigoAutor (bug da API).
            // Filtra por tipo legislativo para excluir requerimentos, indicações, ofícios, etc.
            $siglasProposta = ['PL', 'PLC', 'PLS', 'PLN', 'PLP', 'PEC', 'PDL', 'PRS', 'MPV', 'PDS', 'SFO'];

            $data = $fetch($base . '/dadosabertos/senador/' . (int)$parlId . '/autorias');
            $list = $toArray($data['MateriasAutoriaParlamentar']['Parlamentar']['Autorias']['Autoria'] ?? []);

            $results = [];
            foreach ($list as $a) {
                $mat   = $a['Materia'] ?? [];
                $sigla = $mat['Sigla'] ?? '';
                if (!$sigla || !in_array($sigla, $siglasProposta)) continue;
                $num  = $mat['Numero'] ?? '';
                $ano  = (int)($mat['Ano'] ?? 0);
                if ($anoFiltro && $ano !== $anoFiltro) continue;
                $desc = trim($mat['DescricaoIdentificacao'] ?? "{$sigla} nº {$num}/{$ano}");
                $results[] = [
                    'id'            => (int)($mat['Codigo'] ?? 0) ?: null,
                    '__str__'       => $desc,
                    'tipo'          => $sigla,
                    'numero'        => (string)$num,
                    'ano'           => $ano ?: null,
                    'ementa'        => $mat['Ementa'] ?? '',
                    'data'          => substr($mat['Data'] ?? '', 0, 10),
                    'texto_integral' => null,
                ];
            }
            return $wrap($results);

        // ── Matéria legislativa (detalhe) ────────────────────────────────────
        } elseif (preg_match('#/materia/materialegislativa/(\d+)/#', $cleanPath, $dm)) {
            $materiaId = (int)$dm[1];
            // Busca detalhe e textos em paralelo
            $det   = $fetch($base . '/dadosabertos/materia/' . $materiaId);
            $textos = $fetch($base . '/dadosabertos/materia/textos/' . $materiaId);

            $mat  = $det['DetalheMateria']['Materia'] ?? [];
            $idn  = $mat['IdentificacaoMateria'] ?? [];
            $base2 = $mat['DadosBasicosMateria']  ?? [];

            $sigla  = $idn['SiglaSubtipoMateria']    ?? ($idn['DescricaoSubtipoMateria'] ?? '');
            $num    = $idn['NumeroMateria']            ?? '';
            $ano    = $idn['AnoMateria']               ?? '';
            $ementa = $base2['EmentaMateria']          ?? '';
            $tramit = ($idn['IndicadorTramitando'] ?? 'S') === 'S';

            // Primeiro URL de texto disponível (senado usa sdleg-getter, não .pdf direto)
            $pdfUrl = null;
            $tList  = $toArray($textos['TextoMateria']['Materia']['Textos']['Texto'] ?? []);
            if (!empty($tList[0]['UrlTexto'])) {
                $pdfUrl = $tList[0]['UrlTexto'];
            }

            if (!$mat && !$pdfUrl) return '{}';

            return json_encode([
                'id'                => $materiaId,
                '__str__'           => trim("{$sigla} nº {$num}/{$ano}"),
                'tipo'              => ['sigla' => $sigla, 'descricao' => $sigla],
                'numero'            => $num,
                'ano'               => (int)$ano,
                'ementa'            => $ementa,
                'data_apresentacao' => substr($base2['DataApresentacao'] ?? '', 0, 10),
                'em_tramitacao'     => $tramit,
                'texto_original'    => $pdfUrl,
            ]);

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
