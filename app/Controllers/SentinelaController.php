<?php
class SentinelaController extends Controller {
    public function index(): void {
        $this->requireAuth();

        $projetoId = (int)(Auth::projetoId() ?? 0);

        if (!$projetoId) {
            $this->redirect('/projetos');
        }

        $pModel  = new Projeto();
        $projeto = $pModel->find($projetoId);

        if (!$projeto) {
            Auth::setProjeto(0, '', '[]');
            $this->redirect('/projetos');
        }

        $arquivos = (new SentinelaArquivo())->byProjeto($projetoId);

        $this->render('sentinela/index', compact('projeto', 'arquivos'));
    }
}
