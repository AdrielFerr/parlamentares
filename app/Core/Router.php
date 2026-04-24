<?php
class Router {
    private array $routes = [];

    public function add(string $method, string $path, string $controller, string $action): void {
        $this->routes[] = compact('method', 'path', 'controller', 'action');
    }

    public function dispatch(): void {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Strip base path prefix
        $base = rtrim(BASE_PATH, '/');
        if ($base && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        if ($uri === '' || $uri === false) $uri = '/';

        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $route['path'] === $uri) {
                $ctrl = new $route['controller']();
                $ctrl->{$route['action']}();
                return;
            }
        }

        // Default: redirect to login or parlamentares
        if ($uri === '/') {
            header('Location: ' . BASE_PATH . (Auth::check() ? '/projetos' : '/login'));
            exit;
        }

        http_response_code(404);
        echo '<h1>404 — Página não encontrada</h1>';
    }
}
