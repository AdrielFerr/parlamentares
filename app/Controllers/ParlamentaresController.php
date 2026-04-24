<?php
class ParlamentaresController extends Controller {
    public function index(): void {
        $this->requireAuth();

        /* Projeto sempre vem da sessão; não aceita mais ?projeto= na URL */
        $projetoId = (int)(Auth::projetoId() ?? 0);

        /* Sem projeto selecionado → volta para seleção */
        if (!$projetoId) {
            $this->redirect('/projetos');
        }

        $pModel  = new Projeto();
        $projeto = $pModel->findComFonte($projetoId);

        /* Se projeto sumiu do banco (arquivado), limpa sessão e redireciona */
        if (!$projeto) {
            Auth::setProjeto(0, '', '[]');
            $this->redirect('/projetos');
        }

        $source      = $projeto['source_key'] ?? 'cmjp';
        $saplBaseUrl = $projeto['fonte_url']   ?? '';

        $this->render('parlamentares/index', compact('projeto', 'source', 'saplBaseUrl'));
    }
}
