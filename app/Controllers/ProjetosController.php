<?php
class ProjetosController extends Controller {

    /* ────────────────────────────────────────────────────────────
     * GET /projetos  —  Tela de seleção (layout sem sidebar)
     * ──────────────────────────────────────────────────────────── */
    public function index(): void {
        $this->requireAuth();

        $model    = new Projeto();
        $user     = Auth::user();

        /* Carrega projetos conforme nível de acesso */
        if (Auth::isSuperAdmin()) {
            $projetos = $model->allWithCliente();
        } else {
            $projetos = $model->byCliente((int)$user['cliente_id']);
        }

        /* parl_total já vem da tabela projetos (salvo pelo JS no primeiro acesso) */
        foreach ($projetos as &$p) {
            $p['parl_count'] = (int)($p['parl_total'] ?? 0);
        }
        unset($p);

        $fontes   = (new FonteLegislativa())->allOrdered();
        $clientes = Auth::isSuperAdmin() ? (new Cliente())->allAtivos() : [];

        /* Usa layout 'projetos' (sem sidebar) */
        $this->render('projetos/index', compact('projetos', 'fontes', 'clientes'), 'projetos');
    }

    /* ────────────────────────────────────────────────────────────
     * POST /projetos/selecionar  —  Salva projeto na sessão (AJAX)
     * ──────────────────────────────────────────────────────────── */
    public function selecionar(): void {
        $this->requireAuth();
        $this->verifyCsrf();

        $id      = (int)($_POST['projeto_id'] ?? 0);
        $model   = new Projeto();
        $projeto = $model->find($id);

        if (!$projeto || !$this->validarAcessoProjeto($projeto)) {
            $this->json(['ok' => false, 'error' => 'Projeto não encontrado ou sem acesso.'], 403);
            return;
        }

        /* Persiste na sessão */
        Auth::setProjeto($id, $projeto['nome'], $projeto['dashboards_json'] ?? '[]');

        $this->json([
            'ok'       => true,
            'redirect' => BASE_PATH . '/parlamentares'
        ]);
    }

    /* ────────────────────────────────────────────────────────────
     * GET /projetos/dados  —  Retorna dados de um projeto (AJAX)
     * ──────────────────────────────────────────────────────────── */
    public function dados(): void {
        $this->requireAuth(1);

        $id      = (int)($_GET['id'] ?? 0);
        $model   = new Projeto();
        $projeto = $model->findComFonte($id);

        if (!$projeto) {
            $this->json(null, 404);
            return;
        }

        /* Não expõe a chave criptografada; indica apenas se existe */
        unset($projeto['openai_key_enc']);
        $this->json($projeto);
    }

    /* ────────────────────────────────────────────────────────────
     * POST /projetos/ajax/criar  —  Criação via modal (AJAX)
     * ──────────────────────────────────────────────────────────── */
    public function ajaxCriar(): void {
        $this->requireAuth(0);
        $this->verifyCsrf();

        [$ok, $erroMsg, $dados] = $this->extrairDadosPost(isNovo: true);
        if (!$ok) { $this->json(['ok' => false, 'error' => $erroMsg]); return; }

        $model = new Projeto();
        $id    = $model->insert($dados);

        $apiKey = trim($_POST['openai_key'] ?? '');
        if ($apiKey) $model->setApiKey($id, $apiKey);

        $this->json(['ok' => true, 'id' => $id]);
    }

    /* ────────────────────────────────────────────────────────────
     * POST /projetos/ajax/editar  —  Edição via modal (AJAX)
     * ──────────────────────────────────────────────────────────── */
    public function ajaxEditar(): void {
        $this->requireAuth(0);
        $this->verifyCsrf();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { $this->json(['ok' => false, 'error' => 'ID inválido.']); return; }

        [$ok, $erroMsg, $dados] = $this->extrairDadosPost(isNovo: false);
        if (!$ok) { $this->json(['ok' => false, 'error' => $erroMsg]); return; }

        $model = new Projeto();
        $model->update($id, $dados);

        $apiKey = trim($_POST['openai_key'] ?? '');
        if ($apiKey) $model->setApiKey($id, $apiKey);

        /* Atualiza sessão se o projeto editado estiver ativo */
        if ((int)Auth::projetoId() === $id) {
            Auth::setProjeto($id, $dados['nome'], $dados['dashboards_json'] ?? '[]');
        }

        $this->json(['ok' => true]);
    }

    /* ────────────────────────────────────────────────────────────
     * Helpers legados (mantidos para não quebrar rotas existentes)
     * ──────────────────────────────────────────────────────────── */
    public function form(): void {
        $this->requireAuth(2);
        $fontes   = (new FonteLegislativa())->allOrdered();
        $clientes = Auth::isSuperAdmin() ? (new Cliente())->allAtivos() : [];
        $this->render('projetos/form', compact('fontes', 'clientes') + ['projeto' => null, 'error' => null]);
    }

    public function store(): void {
        $this->requireAuth(2);
        $this->verifyCsrf();

        $clienteId = Auth::isSuperAdmin()
            ? (int)($_POST['cliente_id'] ?? 0)
            : (int)Auth::clienteId();

        $model = new Projeto();
        $id    = $model->insert([
            'nome'       => trim($_POST['nome'] ?? ''),
            'cliente_id' => $clienteId,
            'fonte_id'   => (int)($_POST['fonte_id'] ?? 0),
            'ativo'      => 1,
        ]);

        $apiKey = trim($_POST['openai_key'] ?? '');
        if ($apiKey) $model->setApiKey($id, $apiKey);

        $this->redirect('/projetos');
    }

    public function edit(): void {
        $this->requireAuth(2);
        $id      = (int)($_GET['id'] ?? 0);
        $projeto = (new Projeto())->find($id);
        if (!$projeto) $this->redirect('/projetos');

        $fontes   = (new FonteLegislativa())->allOrdered();
        $clientes = Auth::isSuperAdmin() ? (new Cliente())->allAtivos() : [];
        $this->render('projetos/form', compact('projeto', 'fontes', 'clientes') + ['error' => null]);
    }

    public function update(): void {
        $this->requireAuth(2);
        $this->verifyCsrf();

        $id    = (int)($_POST['id'] ?? 0);
        $model = new Projeto();
        $model->update($id, [
            'nome'     => trim($_POST['nome'] ?? ''),
            'fonte_id' => (int)($_POST['fonte_id'] ?? 0),
        ]);

        $apiKey = trim($_POST['openai_key'] ?? '');
        if ($apiKey) $model->setApiKey($id, $apiKey);

        $this->redirect('/projetos');
    }

    public function destroy(): void {
        $this->requireAuth(0);
        $this->verifyCsrf();
        $id = (int)($_POST['id'] ?? 0);
        (new Projeto())->update($id, ['ativo' => 0]);
        $this->redirect('/projetos');
    }

    /* ────────────────────────────────────────────────────────────
     * Privados
     * ──────────────────────────────────────────────────────────── */

    /* Extrai e valida os campos POST do modal */
    private function extrairDadosPost(bool $isNovo): array {
        $nome      = trim($_POST['nome'] ?? '');
        $fonteId   = (int)($_POST['fonte_id'] ?? 0);
        $modelo    = trim($_POST['openai_model'] ?? 'gpt-4o');
        $dashJson  = trim($_POST['dashboards_json'] ?? '[]');

        /* Validação básica do JSON de dashboards */
        $dashDecoded = json_decode($dashJson, true);
        if (!is_array($dashDecoded)) $dashJson = '[]';

        if (!$nome)    return [false, 'O nome do projeto é obrigatório.', []];
        if (!$fonteId) return [false, 'Selecione a fonte legislativa.',   []];

        $dados = [
            'nome'            => $nome,
            'fonte_id'        => $fonteId,
            'openai_model'    => $modelo,
            'dashboards_json' => $dashJson,
            'ativo'           => 1,
        ];

        if ($isNovo) {
            $clienteId = Auth::isSuperAdmin()
                ? (int)($_POST['cliente_id'] ?? 0)
                : (int)Auth::clienteId();

            if (!$clienteId) return [false, 'Selecione o cliente.', []];
            $dados['cliente_id'] = $clienteId;
        }

        return [true, '', $dados];
    }

    /* Verifica se o usuário logado pode acessar o projeto */
    private function validarAcessoProjeto(array $projeto): bool {
        if (Auth::isSuperAdmin()) return true;
        return (int)($projeto['cliente_id'] ?? 0) === (int)Auth::clienteId();
    }
}
