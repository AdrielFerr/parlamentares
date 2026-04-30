<?php
class ApiController extends Controller {
    public function proxy(): void {
        $this->requireAuth();

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

        $body = SaplApi::getRaw($path, $source, $extra);
        header('Content-Type: application/json; charset=utf-8');
        echo $body;
        exit;
    }

    public function bulk(): void {
        $this->requireAuth();
        $source = $_GET['source'] ?? DEFAULT_SOURCE;

        $paths = [
            'parlamentares' => '/parlamentares/parlamentar/',
            'legislaturas'  => '/parlamentares/legislatura/',
            'partidos'      => '/parlamentares/partido/',
        ];

        $result = ['parlamentares' => [], 'legislaturas' => [], 'partidos' => [], 'fromCache' => true];

        foreach ($paths as $key => $path) {
            $rows = SaplCache::getByPrefix($source, $path . '&page=');
            foreach ($rows as $data) {
                $decoded = json_decode($data, true);
                if (!empty($decoded['results'])) {
                    $result[$key] = array_merge($result[$key], $decoded['results']);
                }
            }
        }

        if (empty($result['parlamentares']) || empty($result['legislaturas'])) {
            $result['fromCache'] = false;
        }

        $this->json($result);
    }

    public function sincronizar(): void {
        $this->requireAuth();
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
        $source = $_GET['source'] ?? DEFAULT_SOURCE;
        $path   = trim($_GET['path'] ?? '');

        // Aceita caminhos de imagem (permite = para URLs do Senado como sufixo=fotoXXX.jpg)
        if (!$path || !preg_match('#^/[a-zA-Z0-9/_\-\.=]+\.(jpg|jpeg|png|gif|webp)$#i', $path)) {
            http_response_code(400);
            exit;
        }

        // Domínio de imagens pode ser diferente da URL da API (Câmara, Senado)
        $imgDomains = [
            'camara_federal' => 'https://www.camara.leg.br',
            'senado'         => 'https://www.senado.leg.br',
        ];
        $base = $imgDomains[$source] ?? SaplApi::baseUrl($source);
        $url  = $base . $path;

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
            'max_tokens'  => 2048,
        ]);

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ]),
            'content'        => $payload,
            'timeout'        => 60,
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
}
