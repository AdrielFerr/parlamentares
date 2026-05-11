<?php
class AuthController extends Controller {
    public function loginForm(): void {
        if (Auth::check()) {
            $this->redirect('/projetos');
        }
        $this->render('auth/login', ['error' => null], 'auth');
    }

    public function login(): void {
        $this->verifyCsrf();

        $email = trim($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        $model = new Usuario();
        $user  = $model->findByEmail($email);

        if (!$user || !$model->verifyPassword($senha, $user['senha_hash'])) {
            $this->render('auth/login', ['error' => 'E-mail ou senha inválidos.'], 'auth');
            return;
        }

        Auth::login($user);
        $this->redirect('/projetos');
    }

    public function logout(): void {
        Auth::logout();
        $this->redirect('/login');
    }

    public function forgotForm(): void {
        if (Auth::check()) $this->redirect('/projetos');
        $this->render('auth/forgot', ['error' => null, 'success' => null], 'auth');
    }

    public function forgot(): void {
        $this->verifyCsrf();
        $email  = trim($_POST['email'] ?? '');
        $uModel = new Usuario();
        $user   = $uModel->findByEmail($email);

        if ($user) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);
            $uModel->update($user['id'], [
                'reset_token'      => $token,
                'reset_expires_at' => $expires,
            ]);

            $link    = rtrim(APP_URL, '/') . BASE_PATH . '/redefinir-senha?token=' . $token;
            $corpo   = Configuracao::get('email_reset_corpo',   null, Mailer::defaultResetTemplate());
            $assunto = Configuracao::get('email_reset_assunto', null, 'Redefinição de Senha — ' . APP_NAME);
            $html    = str_replace(
                ['{{link}}', '{{nome}}', '{{expira}}'],
                [$link,      htmlspecialchars($user['nome']), '1 hora'],
                $corpo
            );
            Mailer::send($user['email'], $user['nome'], $assunto, $html);
        }

        $this->render('auth/forgot', [
            'error'   => null,
            'success' => 'Se o e-mail existir em nosso sistema, você receberá as instruções em instantes.',
        ], 'auth');
    }

    public function resetForm(): void {
        if (Auth::check()) $this->redirect('/projetos');
        $token = $_GET['token'] ?? '';
        $user  = (new Usuario())->findByResetToken($token);
        $this->render('auth/reset', [
            'error' => $user ? null : 'Link inválido ou expirado.',
            'token' => $user ? $token : '',
        ], 'auth');
    }

    public function reset(): void {
        $this->verifyCsrf();
        $token    = $_POST['token']    ?? '';
        $senha    = $_POST['senha']    ?? '';
        $confirma = $_POST['confirma'] ?? '';
        $uModel   = new Usuario();
        $user     = $uModel->findByResetToken($token);

        if (!$user) {
            $this->render('auth/reset', ['error' => 'Link inválido ou expirado.', 'token' => ''], 'auth');
            return;
        }
        if (strlen($senha) < 6) {
            $this->render('auth/reset', ['error' => 'A senha deve ter pelo menos 6 caracteres.', 'token' => $token], 'auth');
            return;
        }
        if ($senha !== $confirma) {
            $this->render('auth/reset', ['error' => 'As senhas não coincidem.', 'token' => $token], 'auth');
            return;
        }

        $uModel->update($user['id'], [
            'senha_hash'       => password_hash($senha, PASSWORD_BCRYPT),
            'reset_token'      => null,
            'reset_expires_at' => null,
        ]);

        $this->redirect('/login?reset=ok');
    }
}
