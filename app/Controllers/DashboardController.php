<?php
class DashboardController extends Controller {

    /* GET /dashboard?idx=N */
    public function visualizar(): void {
        $this->requireAuth();

        $idx  = (int)($_GET['idx'] ?? -1);
        $dashes = Auth::projetoDashboards();

        if ($idx < 0 || !isset($dashes[$idx])) {
            http_response_code(404);
            exit('Dashboard não encontrado.');
        }

        $dash = $dashes[$idx];
        $baseUrl = trim($dash['url'] ?? '');

        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            exit('URL inválida.');
        }

        $token = trim($dash['token'] ?? '');

        /* Monta a URL do iframe com o token como query param (nunca exposto na barra do navegador) */
        $iframeSrc = $baseUrl;
        if ($token !== '') {
            $iframeSrc .= (str_contains($baseUrl, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
        }

        $nome = htmlspecialchars($dash['nome'] ?? 'Dashboard', ENT_QUOTES);

        $this->render('dashboard/visualizar', compact('iframeSrc', 'nome'));
    }
}
