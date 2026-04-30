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
        $ctx    = $this->getContextCliente();
        $uModel = new Usuario();

        if ($ctx['id'] !== null) {
            // Contexto de cliente: mostra usuários desse cliente
            $usuarios = $uModel->byCliente($ctx['id']);
        } else {
            // Visão global (SuperAdmin sem projeto): só usuários do sistema (sem cliente)
            $usuarios = $uModel->allSistema();
        }

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
        $this->requireSuperAdmin();
        $clientes = (new Cliente())->allAtivos();
        $cModel   = new Cliente();
        $ctx      = ['id' => null, 'nome' => 'Sistema'];
        $this->render('admin/clientes', compact('clientes', 'cModel', 'ctx'), 'admin');
    }

    public function clienteAjaxCreate(): void {
        $this->requireSuperAdmin();
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
        $this->requireSuperAdmin();
        $ctx = ['id' => null, 'nome' => 'Sistema'];
        $this->render('admin/cliente_form', compact('ctx') + ['error' => null], 'admin');
    }

    public function clienteStore(): void {
        $this->requireSuperAdmin();
        $this->verifyCsrf();
        (new Cliente())->insert([
            'nome'  => trim($_POST['nome'] ?? ''),
            'ativo' => 1,
        ]);
        $this->redirect('/admin/clientes');
    }

    public function clienteDestroy(): void {
        $this->requireSuperAdmin();
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

    // ─── Aparência ────────────────────────────────────────────────────────────

    private function aparenciaCid(): ?int {
        // SuperAdmin sempre edita configurações globais (cliente_id NULL),
        // independentemente do projeto ativo. Admins de cliente usam o próprio cliente.
        if (Auth::isSuperAdmin()) return null;
        $ctx = $this->getContextCliente();
        return $ctx['id'];
    }

    public function aparencia(): void {
        $this->requireSuperAdmin();
        $cid     = $this->aparenciaCid();
        $ctx     = Auth::isSuperAdmin() ? ['id' => null, 'nome' => 'Sistema'] : $this->getContextCliente();
        $cfg     = Configuracao::forCliente($cid);
        $success = null;
        $error   = null;
        $this->render('admin/aparencia', compact('ctx', 'cfg', 'success', 'error'), 'admin');
    }

    public function aparenciaSave(): void {
        $this->requireSuperAdmin();
        $this->verifyCsrf();

        $cid   = $this->aparenciaCid();
        $ctx   = Auth::isSuperAdmin() ? ['id' => null, 'nome' => 'Sistema'] : $this->getContextCliente();
        $error = null;

        // Cor accent
        $cor = trim($_POST['cor_accent'] ?? '');
        if ($cor && Configuracao::isValidHex($cor)) {
            Configuracao::set('cor_accent', $cor, $cid);
        } elseif ($cor) {
            $error = 'Cor inválida. Use o formato #RRGGBB.';
        }

        // Upload de logo
        if (!$error && isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['image/png','image/jpeg','image/webp','image/gif'];
            $mime    = mime_content_type($_FILES['logo']['tmp_name']);
            if (!in_array($mime, $allowed)) {
                $error = 'Formato de imagem inválido. Use PNG, JPG, WebP ou GIF.';
            } elseif ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                $error = 'A imagem não pode ter mais de 2 MB.';
            } else {
                $dir = ROOT . '/public/uploads/logos/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $ext      = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'][$mime];
                $filename = 'logo_' . ($cid ?? 'global') . '_' . time() . '.' . $ext;
                $dest     = $dir . $filename;

                // Remove logo anterior
                $old = Configuracao::get('logo_url', $cid, '');
                if ($old) {
                    $oldFile = ROOT . $old;
                    if (is_file($oldFile)) unlink($oldFile);
                }

                if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                    Configuracao::set('logo_url', '/public/uploads/logos/' . $filename, $cid);
                } else {
                    $error = 'Falha ao salvar a imagem. Verifique as permissões da pasta.';
                }
            }
        }

        $cfg     = Configuracao::forCliente($cid);
        $success = $error ? null : 'Aparência salva com sucesso.';
        $this->render('admin/aparencia', compact('ctx', 'cfg', 'success', 'error'), 'admin');
    }

    public function aparenciaLogoRemove(): void {
        $this->requireSuperAdmin();
        $this->verifyCsrf();

        $cid = $this->aparenciaCid();
        $url = Configuracao::get('logo_url', $cid, '');
        if ($url) {
            $file = ROOT . $url;
            if (is_file($file)) unlink($file);
            Configuracao::set('logo_url', '', $cid);
        }

        $this->redirect('/admin/aparencia');
    }
}
