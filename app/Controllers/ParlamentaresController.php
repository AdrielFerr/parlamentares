<?php
class ParlamentaresController extends Controller {
    public function index(): void {
        $this->requireAuth();

        $projetoId = (int)(Auth::projetoId() ?? 0);

        if (!$projetoId) {
            $this->redirect('/projetos');
        }

        $pModel  = new Projeto();
        $projeto = $pModel->findComFonte($projetoId);

        if (!$projeto) {
            Auth::setProjeto(0, '', '[]');
            $this->redirect('/projetos');
        }

        $cargoKey = trim($_GET['cargo'] ?? '');
        $uf       = strtoupper($projeto['uf'] ?? '');

        /* Sem cargo selecionado → tela de seleção */
        if (!$cargoKey) {
            $extrasMap = require ROOT . '/config/estados.php';
            $cargos    = $this->buildCargosForState($uf, $extrasMap);
            $this->render('parlamentares/cargos', compact('projeto', 'cargos', 'uf'));
            return;
        }

        /* Cargo selecionado → lista filtrada */
        $source = $cargoKey;

        if (in_array($source, ['camara_federal', 'senado', 'governadores', 'prefeitos']) && $uf) {
            Auth::setUfFilter($uf);
            $ufFilter = $uf;
        } else {
            Auth::setUfFilter('');
            $ufFilter = '';
        }

        /* URL base da fonte para links externos */
        $db = Database::connect();
        $st = $db->prepare("SELECT url FROM fontes_legislativas WHERE source_key = ? LIMIT 1");
        $st->execute([$source]);
        $fonteRow    = $st->fetch(PDO::FETCH_ASSOC);
        $saplBaseUrl = $fonteRow['url'] ?? ($projeto['fonte_url'] ?? '');

        /* Label do cargo para o breadcrumb */
        $meta       = $this->cargoMeta();
        $cargoLabel = $meta[$source]['label'] ?? $source;

        $this->render('parlamentares/index', compact('projeto', 'source', 'saplBaseUrl', 'ufFilter', 'cargoLabel'));
    }

    /* ─── Monta lista de cargos disponíveis para o estado ─── */
    private function buildCargosForState(string $uf, array $extrasMap): array {
        $db   = Database::connect();
        $meta = $this->cargoMeta();

        /* Cargos base fixos */
        $cargos = [
            $meta['governador'],
            $meta['senado'],
            $meta['camara_federal'],
        ];

        /* Extras por UF (estadual + municipal) */
        $extras         = $extrasMap[$uf] ?? [];
        $estaduaisAdded = false;

        foreach ($extras as $fe) {
            if ($fe['cargo'] === 'estadual' && !$estaduaisAdded) {
                $c        = $meta['dep_estadual'];
                $c['key'] = $fe['source_key'];
                $c['sub'] = $fe['label'];
                $cargos[] = $c;
                $estaduaisAdded = true;
            } elseif ($fe['cargo'] === 'municipal') {
                $c        = $meta['vereadores'];
                $c['key'] = $fe['source_key'];
                $c['sub'] = $fe['label'];
                $cargos[] = $c;
            }
        }

        /* Placeholder estadual se não estiver no config */
        if (!$estaduaisAdded) {
            $cargos[] = $meta['dep_estadual'];
        }

        /* Prefeito — placeholder sempre */
        $cargos[] = $meta['prefeito'];

        /* Conta parlamentares por cargo */
        foreach ($cargos as &$c) {
            $key = $c['key'];

            /* Chaves sem fonte real → sempre em andamento */
            if (in_array($key, ['dep_estadual', 'vereadores'])) {
                $c['total']  = 0;
                $c['status'] = 'andamento';
                continue;
            }

            if (in_array($key, ['camara_federal', 'senado', 'governadores', 'prefeitos']) && $uf) {
                $st = $db->prepare("SELECT COUNT(*) FROM parl_parlamentares WHERE source_key=? AND uf=?");
                $st->execute([$key, $uf]);
            } else {
                $st = $db->prepare("SELECT COUNT(*) FROM parl_parlamentares WHERE source_key=?");
                $st->execute([$key]);
            }

            $total       = (int)$st->fetchColumn();
            $c['total']  = $total;
            $c['status'] = $total > 0 ? 'disponivel' : 'andamento';
        }
        unset($c);

        return $cargos;
    }

    /* ─── Metadados dos tipos de cargo ─── */
    private function cargoMeta(): array {
        return [
            'governador'     => ['key' => 'governadores',   'label' => 'Governador',         'sub' => 'Poder Executivo Estadual',  'icon' => 'ph-star',        'cor' => '#7c3aed'],
            'senado'         => ['key' => 'senado',         'label' => 'Senadores',           'sub' => 'Senado Federal',            'icon' => 'ph-buildings',   'cor' => '#1d4ed8'],
            'camara_federal' => ['key' => 'camara_federal', 'label' => 'Deputados Federais',  'sub' => 'Câmara dos Deputados',      'icon' => 'ph-users',        'cor' => '#16a34a'],
            'dep_estadual'   => ['key' => 'dep_estadual',   'label' => 'Deputados Estaduais', 'sub' => 'Assembleia Legislativa',    'icon' => 'ph-user-list',   'cor' => '#ea580c'],
            'vereadores'     => ['key' => 'vereadores',     'label' => 'Vereadores',          'sub' => 'Câmara Municipal',          'icon' => 'ph-users-three', 'cor' => '#ca8a04'],
            'prefeito'       => ['key' => 'prefeitos',      'label' => 'Prefeitos',           'sub' => 'Regiões Metropolitanas',   'icon' => 'ph-city',        'cor' => '#16a34a'],
        ];
    }
}
