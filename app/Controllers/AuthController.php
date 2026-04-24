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
}
