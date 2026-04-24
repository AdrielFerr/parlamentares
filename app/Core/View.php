<?php
class View {
    public static function render(string $viewPath, array $data = [], string $layout = 'main'): void {
        extract($data);
        $csrf = Auth::csrfToken();

        ob_start();
        require APP . '/Views/' . $viewPath . '.php';
        $content = ob_get_clean();

        require APP . '/Views/layouts/' . $layout . '.php';
    }

    public static function partial(string $path, array $data = []): void {
        extract($data);
        require APP . '/Views/' . $path . '.php';
    }

    public static function json(mixed $payload, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function redirect(string $path): void {
        header('Location: ' . BASE_PATH . $path);
        exit;
    }
}
