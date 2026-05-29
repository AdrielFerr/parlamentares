<?php
class ProjetosController extends Controller {

    /* ────────────────────────────────────────────────────────────
     * GET /projetos  —  Tela de seleção (layout sem sidebar)
     * ──────────────────────────────────────────────────────────── */
    public function index(): void {
        $this->requireAuth();

        Auth::setProjeto(0, '', '[]');
        Auth::setUfFilter('');

        $model = new Projeto();
        $user  = Auth::user();

        /* Carrega projetos conforme nível de acesso */
        if (Auth::isSuperAdmin()) {
            $projetos = $model->allWithCliente();
        } elseif (Auth::nivel() === 1 && Auth::clienteId() === null) {
            $projetos = $model->byAdminUser((int)Auth::id());
            $extras   = $model->byUsuariosExtra((int)Auth::id());
            $existIds = array_column($projetos, 'id');
            foreach ($extras as $e) {
                if (!in_array($e['id'], $existIds)) { $projetos[] = $e; $existIds[] = $e['id']; }
            }
        } else {
            $projetos = $model->byCliente((int)$user['cliente_id']);
            $extras   = $model->byUsuariosExtra((int)Auth::id());
            $existIds = array_column($projetos, 'id');
            foreach ($extras as $e) {
                if (!in_array($e['id'], $existIds)) { $projetos[] = $e; $existIds[] = $e['id']; }
            }
        }

        /* Contagem de parlamentares */
        foreach ($projetos as &$p) {
            $parlTotal = (int)($p['parl_total'] ?? 0);
            if ($parlTotal === 0 && !empty($p['source_key'])) {
                $parlTotal = $model->countParlamentares($p['source_key']);
            }
            $p['parl_count'] = $parlTotal;
        }
        unset($p);

        /* Agrupa projetos por UF */
        $extrasMap   = require ROOT . '/config/estados.php';
        $sourceUfMap = [];
        foreach ($extrasMap as $uf => $fontes) {
            foreach ($fontes as $fe) $sourceUfMap[$fe['source_key']] = $uf;
        }

        $projetosPorUf = [];
        foreach ($projetos as $p) {
            $uf = strtoupper($p['uf'] ?? '');
            if (!$uf) $uf = $sourceUfMap[$p['source_key'] ?? ''] ?? '';
            $projetosPorUf[$uf ?: '_sem_estado'][] = $p;
        }

        /* Carrega estados do banco */
        $db = Database::connect();
        $estadosRows = $db->query(
            "SELECT uf, nome, regiao FROM estados WHERE ativo=1 ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC);

        $estados = [];
        foreach ($estadosRows as $r) {
            $estados[$r['uf']] = ['nome' => $r['nome'], 'regiao' => $r['regiao']];
        }

        /* Para não-admin: mostra só estados com projetos */
        if (!Auth::isSuperAdmin()) {
            $estados = array_intersect_key($estados, $projetosPorUf);
        }

        $clientes     = Auth::isSuperAdmin() ? (new Cliente())->allAtivos() : [];
        $adminUsers   = Auth::isSuperAdmin() ? (new Usuario())->allAdministradores() : [];
        $todoUsuarios = Auth::isSuperAdmin() ? (new Usuario())->allAssignaveis() : [];

        $this->render('projetos/index', compact('estados', 'projetosPorUf', 'projetos', 'clientes', 'adminUsers', 'todoUsuarios'), 'projetos');
    }

    /* ────────────────────────────────────────────────────────────
     * POST /projetos/selecionar  —  Salva projeto na sessão (AJAX)
     * ──────────────────────────────────────────────────────────── */
    public function selecionar(): void {
        $this->requireAuth();
        $this->verifyCsrf();

        $id      = (int)($_POST['projeto_id'] ?? 0);
        $model   = new Projeto();
        $projeto = $model->findComFonte($id);

        if (!$projeto || !$this->validarAcessoProjeto($projeto)) {
            $this->json(['ok' => false, 'error' => 'Projeto não encontrado ou sem acesso.'], 403);
            return;
        }

        /* Persiste projeto na sessão */
        Auth::setProjeto($id, $projeto['nome'], $projeto['dashboards_json'] ?? '[]');

        /* Define filtro de UF para fontes nacionais */
        $sourceKey = $projeto['source_key'] ?? '';
        $uf        = strtoupper($projeto['uf'] ?? '');
        if ($uf && in_array($sourceKey, ['camara_federal', 'senado'])) {
            Auth::setUfFilter($uf);
        } else {
            Auth::setUfFilter('');
        }

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
        $projeto['admin_ids']   = $model->getAdminIds($id);
        $projeto['usuario_ids'] = $model->getUsuarioIds($id);
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

        $usuarioIds = json_decode($_POST['usuario_ids'] ?? '[]', true) ?: [];
        $model->setUsuarios($id, $usuarioIds);

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

        $usuarioIds = json_decode($_POST['usuario_ids'] ?? '[]', true) ?: [];
        $model->setUsuarios($id, $usuarioIds);

        /* Atualiza sessão se o projeto editado estiver ativo */
        if ((int)Auth::projetoId() === $id) {
            Auth::setProjeto($id, $dados['nome'], $dados['dashboards_json'] ?? '[]');
        }

        $this->json(['ok' => true]);
    }

    /* ────────────────────────────────────────────────────────────
     * GET /projetos/estado/{uf}  —  Lista de projetos de um estado (SA)
     * ──────────────────────────────────────────────────────────── */
    public function estadoProjetos(string $uf): void {
        $this->requireSuperAdmin();
        $uf = strtoupper(trim($uf));

        $db = Database::connect();
        $st = $db->prepare("SELECT uf, nome, regiao FROM estados WHERE uf = ? AND ativo = 1");
        $st->execute([$uf]);
        $estado = $st->fetch(PDO::FETCH_ASSOC);
        if (!$estado) { $this->redirect('/projetos'); return; }

        $model    = new Projeto();
        $projetos = $model->byUf($uf);
        foreach ($projetos as &$p) {
            $parlTotal = (int)($p['parl_total'] ?? 0);
            if ($parlTotal === 0 && !empty($p['source_key'])) {
                $parlTotal = $model->countParlamentares($p['source_key']);
            }
            $p['parl_count'] = $parlTotal;
        }
        unset($p);

        $clientes     = (new Cliente())->allAtivos();
        $todoUsuarios = (new Usuario())->allAssignaveis();

        $this->render('projetos/estado', compact('estado', 'projetos', 'uf', 'clientes', 'todoUsuarios'), 'projetos');
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
        $nome     = trim($_POST['nome'] ?? '');
        $uf       = strtoupper(trim($_POST['uf'] ?? ''));
        $modelo   = trim($_POST['openai_model'] ?? 'gpt-4o');
        $dashJson = trim($_POST['dashboards_json'] ?? '[]');

        /* Validação básica do JSON de dashboards */
        $dashDecoded = json_decode($dashJson, true);
        if (!is_array($dashDecoded)) $dashJson = '[]';

        if (!$nome) return [false, 'O nome do projeto é obrigatório.', []];

        $dados = [
            'nome'            => $nome,
            'uf'              => $uf,
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
        return (new Projeto())->canAccess(
            (int)$projeto['id'],
            (int)Auth::id(),
            Auth::nivel(),
            Auth::clienteId()
        );
    }
}
