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
