<?php
class Auth {
    // Níveis: 0=SuperAdmin, 1=ClienteAdmin, 2=Gestor, 3=Analista, 4=Visualizador
    public static function check(): bool {
        return isset($_SESSION['user_id']);
    }

    public static function user(): ?array {
        return $_SESSION['user'] ?? null;
    }

    public static function id(): ?int {
        return $_SESSION['user_id'] ?? null;
    }

    public static function nivel(): int {
        return $_SESSION['user']['nivel'] ?? 99;
    }

    public static function clienteId(): ?int {
        return $_SESSION['user']['cliente_id'] ?? null;
    }

    public static function projetoId(): ?int {
        return $_SESSION['projeto_id'] ?? null;
    }

    /* $nome e $dashboardsJson: null = não sobrescrever o que já está na sessão */
    public static function setProjeto(int $id, ?string $nome = null, ?string $dashboardsJson = null): void {
        $_SESSION['projeto_id'] = $id;
        if ($nome !== null)          $_SESSION['projeto_nome']       = $nome;
        if ($dashboardsJson !== null) $_SESSION['projeto_dashboards'] = $dashboardsJson;
    }

    public static function projetoNome(): string {
        return $_SESSION['projeto_nome'] ?? '';
    }

    public static function projetoDashboards(): array {
        $json = $_SESSION['projeto_dashboards'] ?? '[]';
        return json_decode($json, true) ?: [];
    }

    public static function login(array $user): void {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user']    = $user;
    }

    public static function logout(): void {
        session_destroy();
    }

    public static function require(int $maxNivel = 4): void {
        if (!self::check()) {
            header('Location: ' . BASE_PATH . '/login');
            exit;
        }
        if (self::nivel() > $maxNivel) {
            http_response_code(403);
            exit('Acesso negado.');
        }
    }

    public static function isSuperAdmin(): bool {
        return self::nivel() === 0;
    }

    public static function csrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(): void {
        $token = $_POST['_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            exit('CSRF token inválido.');
        }
    }
}
