<?php
class AdminController extends Controller {

    /**
     * Retorna o cliente de contexto com base no projeto ativo na sessão.
     * - Projeto ativo → cliente daquele projeto (mesmo que seja SuperAdmin)
     * - SuperAdmin sem projeto ativo → visão global (id = null)
     * - Outros sem projeto ativo → seu próprio cliente
     */
    private function getContextCliente(): array {
        $projetoId = (int) Auth::projetoId();
        if ($projetoId) {
            $proj = (new Projeto())->find($projetoId);
            if ($proj) {
                $clienteId = (int) $proj['cliente_id'];
                $cli = (new Cliente())->find($clienteId);
                return ['id' => $clienteId, 'nome' => $cli['nome'] ?? ''];
            }
        }
        if (Auth::isSuperAdmin()) {
            return ['id' => null, 'nome' => 'Sistema'];
        }
        $clienteId = (int) Auth::clienteId();
        $cli = (new Cliente())->find($clienteId);
        return ['id' => $clienteId, 'nome' => $cli['nome'] ?? ''];
    }

    public function index(): void {
        $this->requireAuth(1);
        if (Auth::nivel() === 1) {
            $this->redirect('/admin/usuarios');
            return;
        }
        $ctx      = $this->getContextCliente();
        $pModel   = new Projeto();
        $projetos = $ctx['id']
            ? $pModel->byCliente($ctx['id'])
            : $pModel->allWithCliente();
        $this->render('admin/index', compact('projetos', 'ctx'), 'admin');
    }

    public function usuarios(): void {
        $this->requireAuth(1);
        $ctx      = $this->getContextCliente();
        $uModel   = new Usuario();
        $usuarios = $ctx['id']
            ? $uModel->byCliente($ctx['id'])
            : $uModel->all('nome');
        $layout = Auth::nivel() === 1 ? 'main' : 'admin';
        $this->render('admin/usuarios', compact('usuarios', 'uModel', 'ctx'), $layout);
    }

    public function usuarioForm(): void {
        $this->requireAuth(1);
        $ctx      = $this->getContextCliente();
        $clientes = (Auth::isSuperAdmin() && $ctx['id'] === null)
            ? (new Cliente())->allAtivos()
            : [];
        $this->render('admin/usuario_form', compact('clientes', 'ctx') + ['error' => null]);
    }

    public function usuarioStore(): void {
        $this->requireAuth(1);
        $this->verifyCsrf();

        $ctx = $this->getContextCliente();

        // Se há contexto de cliente (projeto ativo ou não-SuperAdmin), usa esse cliente.
        // SuperAdmin em visão global pode escolher pelo formulário.
        $clienteId = $ctx['id'] ?? ((int)($_POST['cliente_id'] ?? 0) ?: null);

        $nivel = (int) ($_POST['nivel'] ?? 4);
        if (!Auth::isSuperAdmin() && $nivel < 1) $nivel = 1;

        (new Usuario())->createUser(
            trim($_POST['nome']  ?? ''),
            trim($_POST['email'] ?? ''),
            $_POST['senha'] ?? '',
            $nivel,
            $clienteId
        );

        $this->redirect('/admin/usuarios');
    }

    public function usuarioSenha(): void {
        $this->requireAuth(1);
        $this->verifyCsrf();
        $id    = (int) ($_POST['id'] ?? 0);
        $senha = $_POST['senha'] ?? '';
        if ($id && strlen($senha) >= 6) {
            $ctx    = $this->getContextCliente();
            $target = (new Usuario())->find($id);
            // Valida que o alvo pertence ao mesmo cliente do contexto
            if ($target && ($ctx['id'] === null || (int)$target['cliente_id'] === $ctx['id'])) {
                (new Usuario())->update($id, ['senha_hash' => password_hash($senha, PASSWORD_BCRYPT)]);
            }
        }
        $this->redirect('/admin/usuarios');
    }

    public function usuarioDestroy(): void {
        $this->requireAuth(1);
        $this->verifyCsrf();
        $id     = (int) ($_POST['id'] ?? 0);
        $ctx    = $this->getContextCliente();
        $target = (new Usuario())->find($id);
        if ($target && ($ctx['id'] === null || (int)$target['cliente_id'] === $ctx['id'])) {
            (new Usuario())->update($id, ['ativo' => 0]);
        }
        $this->redirect('/admin/usuarios');
    }

    public function clientes(): void {
        $this->requireAuth(0);
        $clientes = (new Cliente())->allAtivos();
        $cModel   = new Cliente();
        $ctx      = ['id' => null, 'nome' => 'Sistema'];
        $this->render('admin/clientes', compact('clientes', 'cModel', 'ctx'), 'admin');
    }

    public function clienteAjaxCreate(): void {
        $this->requireAuth(0);
        $this->verifyCsrf();
        $nome = trim($_POST['nome'] ?? '');
        if (!$nome) {
            $this->json(['ok' => false, 'error' => 'Nome do cliente é obrigatório.']);
            return;
        }
        $id = (new Cliente())->insert(['nome' => $nome, 'ativo' => 1]);
        $this->json(['ok' => true, 'id' => $id, 'nome' => $nome]);
    }

    public function clienteForm(): void {
        $this->requireAuth(0);
        $ctx = ['id' => null, 'nome' => 'Sistema'];
        $this->render('admin/cliente_form', compact('ctx') + ['error' => null], 'admin');
    }

    public function clienteStore(): void {
        $this->requireAuth(0);
        $this->verifyCsrf();
        (new Cliente())->insert([
            'nome'  => trim($_POST['nome'] ?? ''),
            'ativo' => 1,
        ]);
        $this->redirect('/admin/clientes');
    }

    public function clienteDestroy(): void {
        $this->requireAuth(0);
        $this->verifyCsrf();
        $id = (int) ($_POST['id'] ?? 0);
        (new Cliente())->update($id, ['ativo' => 0]);
        $this->redirect('/admin/clientes');
    }

    public function perfilForm(): void {
        $this->requireAuth();
        $ctx     = $this->getContextCliente();
        $error   = null;
        $success = null;
        $this->render('admin/perfil', compact('error', 'success', 'ctx'), 'admin');
    }

    public function perfilJson(): void {
        $this->requireAuth();
        $this->verifyCsrf();

        $id     = (int) Auth::id();
        $uModel = new Usuario();
        $data   = [];
        $error  = null;

        $nome = trim($_POST['nome'] ?? '');
        if ($nome) $data['nome'] = $nome;

        $email = trim($_POST['email'] ?? '');
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'E-mail inválido.';
        } elseif ($email) {
            $data['email'] = $email;
        }

        $senha    = $_POST['senha']    ?? '';
        $confirma = $_POST['confirma'] ?? '';
        if (!$error && $senha) {
            if (strlen($senha) < 6) {
                $error = 'A senha deve ter pelo menos 6 caracteres.';
            } elseif ($senha !== $confirma) {
                $error = 'As senhas não coincidem.';
            } else {
                $data['senha_hash'] = password_hash($senha, PASSWORD_BCRYPT);
            }
        }

        if (!$error && !empty($data)) {
            $uModel->update($id, $data);
            $user = $uModel->find($id);
            if ($user) $_SESSION['user'] = $user;
            $msg = 'Perfil atualizado com sucesso.';
        } elseif (!$error) {
            $msg = 'Nenhuma alteração enviada.';
        } else {
            $msg = $error;
        }

        header('Content-Type: application/json');
        echo json_encode(['ok' => !$error, 'msg' => $msg]);
        exit;
    }

    public function perfilStore(): void {
        $this->requireAuth();
        $this->verifyCsrf();

        $id     = (int) Auth::id();
        $uModel = new Usuario();
        $ctx    = $this->getContextCliente();
        $data   = [];
        $error  = null;

        $nome = trim($_POST['nome'] ?? '');
        if ($nome) $data['nome'] = $nome;

        $email = trim($_POST['email'] ?? '');
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'E-mail inválido.';
        } elseif ($email) {
            $data['email'] = $email;
        }

        $senha    = $_POST['senha']    ?? '';
        $confirma = $_POST['confirma'] ?? '';
        if ($senha) {
            if (strlen($senha) < 6) {
                $error = 'A senha deve ter pelo menos 6 caracteres.';
            } elseif ($senha !== $confirma) {
                $error = 'As senhas não coincidem.';
            } else {
                $data['senha_hash'] = password_hash($senha, PASSWORD_BCRYPT);
            }
        }

        $success = null;
        if (!$error && !empty($data)) {
            $uModel->update($id, $data);
            $user = $uModel->find($id);
            if ($user) $_SESSION['user'] = $user;
            $success = 'Perfil atualizado com sucesso.';
        } elseif (!$error) {
            $success = 'Nenhuma alteração enviada.';
        }

        $this->render('admin/perfil', compact('error', 'success', 'ctx'), 'admin');
    }
}
