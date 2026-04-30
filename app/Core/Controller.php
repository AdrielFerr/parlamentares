<?php
abstract class Controller {
    protected function render(string $view, array $data = [], string $layout = 'main'): void {
        View::render($view, $data, $layout);
    }

    protected function json(mixed $payload, int $status = 200): void {
        View::json($payload, $status);
    }

    protected function redirect(string $path): void {
        View::redirect($path);
    }

    protected function requireAuth(int $maxNivel = 4): void {
        Auth::require($maxNivel);
    }

    protected function requireSuperAdmin(): void {
        Auth::require(0);
        if (!Auth::isSuperAdmin()) {
            http_response_code(403);
            exit('Acesso restrito ao SuperAdmin.');
        }
    }

    protected function verifyCsrf(): void {
        Auth::verifyCsrf();
    }
}
