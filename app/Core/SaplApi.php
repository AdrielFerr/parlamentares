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

        $page         = max(1, (int)($params['page'] ?? 1));
        $camaraParams = ['pagina' => $page, 'itens' => 100];
        $normalizeAs  = null;

        if (str_contains($path, '/parlamentares/parlamentar')) {
            $camaraPath  = '/api/v2/deputados';
            $normalizeAs = 'deputado';

        } elseif (str_contains($path, '/parlamentares/legislatura')) {
            $camaraPath  = '/api/v2/legislaturas';
            $camaraParams['itens'] = 50;
            $normalizeAs = 'legislatura';

        } elseif (str_contains($path, '/parlamentares/partido')) {
            $camaraPath  = '/api/v2/partidos';
            $normalizeAs = 'partido';

        } elseif (str_contains($path, '/parlamentares/mandato')) {
            $camaraPath  = '/api/v2/deputados';
            $leg = $params['legislatura'] ?? '';
            if ($leg) $camaraParams['idLegislatura'] = $leg;
            $normalizeAs = 'mandato';

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
}
