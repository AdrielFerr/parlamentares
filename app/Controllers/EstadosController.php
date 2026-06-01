<?php
class EstadosController extends Controller {

    /* ──────────────────────────────────────────────────────────────
     * GET /estados  —  Grade de estados
     * ────────────────────────────────────────────────────────────── */
    public function index(): void {
        $this->requireAuth();
        Auth::setProjeto(0, '', '[]');
        Auth::setUfFilter('');

        $db          = Database::connect();
        $extrasMap   = require ROOT . '/config/estados.php'; // fontes extras por UF
        $sourceUfMap = $this->buildSourceUfMapFromExtras($extrasMap);

        /* Carrega todos os estados ativos do banco */
        $rows = $db->query("SELECT uf, nome, regiao FROM estados WHERE ativo=1 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
        $estados = [];
        foreach ($rows as $r) {
            $estados[$r['uf']] = [
                'nome'         => $r['nome'],
                'regiao'       => $r['regiao'],
                'fontes_extras'=> $extrasMap[$r['uf']] ?? [],
            ];
        }

        /* Para clientes: filtra apenas os estados dos projetos deles */
        if (!Auth::isSuperAdmin()) {
            $projetos    = $this->getAllProjectsList(new Projeto());
            $meusEstados = [];
            foreach ($projetos as $p) {
                $uf = strtoupper($p['uf'] ?? '');
                if (!$uf) $uf = $sourceUfMap[$p['source_key'] ?? ''] ?? '';
                if ($uf && isset($estados[$uf])) {
                    $meusEstados[$uf] = true;
                }
            }
            $estados = array_intersect_key($estados, $meusEstados);
        }

        /* Contagens para os estados visíveis */
        $counts = [];
        if (!empty($estados)) {
            $ufs = array_keys($estados);
            $ph  = implode(',', array_fill(0, count($ufs), '?'));
            $st  = $db->prepare("
                SELECT source_key, uf, COUNT(*) AS total
                FROM parl_parlamentares
                WHERE source_key IN ('camara_federal','senado') AND uf IN ($ph)
                GROUP BY source_key, uf
            ");
            $st->execute($ufs);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $counts[$r['source_key']][$r['uf']] = (int)$r['total'];
            }

            $extraKeys = [];
            foreach ($estados as $data) {
                foreach ($data['fontes_extras'] as $fe) $extraKeys[] = $fe['source_key'];
            }
            if ($extraKeys) {
                $ph2 = implode(',', array_fill(0, count($extraKeys), '?'));
                $st2 = $db->prepare("SELECT source_key, COUNT(*) AS total FROM parl_parlamentares WHERE source_key IN ($ph2) GROUP BY source_key");
                $st2->execute($extraKeys);
                foreach ($st2->fetchAll() as $r) $counts['extras'][$r['source_key']] = (int)$r['total'];
            }
        }

        $layoutTitle = 'Estados';
        $this->render('estados/index', compact('estados', 'counts', 'layoutTitle'), 'projetos');
    }

    /* ──────────────────────────────────────────────────────────────
     * GET /estados/{uf}  —  Seleção de cargo/âmbito
     * ────────────────────────────────────────────────────────────── */
    public function show(string $uf): void {
        $this->requireAuth();

        $uf        = strtoupper($uf);
        $db        = Database::connect();
        $extrasMap = require ROOT . '/config/estados.php';

        $row = $db->prepare("SELECT uf, nome, regiao FROM estados WHERE uf=? AND ativo=1");
        $row->execute([$uf]);
        $estadoRow = $row->fetch(PDO::FETCH_ASSOC);

        if (!$estadoRow) {
            $this->redirect('/estados');
        }

        $estado = [
            'nome'         => $estadoRow['nome'],
            'regiao'       => $estadoRow['regiao'],
            'fontes_extras'=> $extrasMap[$uf] ?? [],
        ];

        /* Projetos acessíveis a este usuário, filtrados por estado */
        $allEstados       = [$uf => $estado];
        $projetosBySource = $this->projetosForState($uf, $allEstados, $extrasMap);

        /* Métricas nacionais */
        $nationalCounts = [];
        foreach (['camara_federal', 'senado'] as $sk) {
            if (isset($projetosBySource[$sk])) {
                $st = $db->prepare("SELECT COUNT(*) FROM parl_parlamentares WHERE source_key=? AND uf=?");
                $st->execute([$sk, $uf]);
                $nationalCounts[$sk] = (int)$st->fetchColumn();
            }
        }

        /* Métricas extras */
        $extraCounts  = [];
        $extraSources = array_column($estado['fontes_extras'], 'source_key');
        if ($extraSources) {
            $ph = implode(',', array_fill(0, count($extraSources), '?'));
            $st = $db->prepare("SELECT source_key, COUNT(*) AS total FROM parl_parlamentares WHERE source_key IN ($ph) GROUP BY source_key");
            $st->execute($extraSources);
            foreach ($st->fetchAll() as $r) $extraCounts[$r['source_key']] = (int)$r['total'];
        }

        $layoutTitle = htmlspecialchars($estado['nome']);
        $this->render('estados/show', compact('uf', 'estado', 'projetosBySource', 'nationalCounts', 'extraCounts', 'layoutTitle'), 'projetos');
    }

    /* ──────────────────────────────────────────────────────────────
     * POST /estados/{uf}/selecionar
     * ────────────────────────────────────────────────────────────── */
    public function selecionar(string $uf): void {
        $this->requireAuth();
        $this->verifyCsrf();

        $uf        = strtoupper($uf);
        $sourceKey = trim($_POST['source_key'] ?? '');
        $applyUf   = !empty($_POST['apply_uf']);

        if (!$sourceKey) {
            $this->json(['ok' => false, 'error' => 'Fonte não informada.'], 400);
            return;
        }

        $db        = Database::connect();
        $extrasMap = require ROOT . '/config/estados.php';

        $row = $db->prepare("SELECT uf FROM estados WHERE uf=? AND ativo=1");
        $row->execute([$uf]);
        if (!$row->fetch()) {
            $this->json(['ok' => false, 'error' => 'Estado inválido.'], 400);
            return;
        }

        $estado   = [[$uf => ['fontes_extras' => $extrasMap[$uf] ?? []]]];
        $projetos = $this->projetosForState($uf, [$uf => ['fontes_extras' => $extrasMap[$uf] ?? []]], $extrasMap);
        $projeto  = $projetos[$sourceKey] ?? null;

        if (!$projeto) {
            $this->json(['ok' => false, 'error' => 'Projeto não encontrado ou sem acesso.'], 403);
            return;
        }

        Auth::setProjeto((int)$projeto['id'], $projeto['nome'], $projeto['dashboards_json'] ?? '[]');
        Auth::setUfFilter($applyUf ? $uf : '');

        $this->json([
            'ok'       => true,
            'redirect' => BASE_PATH . '/parlamentares',
        ]);
    }

    /* ── Projetos deste usuário que pertencem ao estado $uf ── */
    private function projetosForState(string $uf, array $estadosCtx, array $extrasMap): array {
        $all         = $this->getAllProjectsList(new Projeto());
        $sourceUfMap = $this->buildSourceUfMapFromExtras($extrasMap);

        $bySource = [];
        foreach ($all as $p) {
            $sk = $p['source_key'] ?? '';
            if (!$sk) continue;

            if (in_array($sk, ['camara_federal', 'senado'])) {
                if (strtoupper($p['uf'] ?? '') === $uf) $bySource[$sk] = $p;
            } else {
                $derivedUf = $sourceUfMap[$sk] ?? strtoupper($p['uf'] ?? '');
                if ($derivedUf === $uf) $bySource[$sk] = $p;
            }
        }
        return $bySource;
    }

    /* ── source_key → UF a partir do mapa de extras ── */
    private function buildSourceUfMapFromExtras(array $extrasMap): array {
        $map = [];
        foreach ($extrasMap as $uf => $fontes) {
            foreach ($fontes as $fe) $map[$fe['source_key']] = $uf;
        }
        return $map;
    }

    /* ── Lista todos os projetos acessíveis ao usuário logado ── */
    private function getAllProjectsList(Projeto $model): array {
        $user = Auth::user();
        if (Auth::isSuperAdmin()) return $model->allWithCliente();
        if (Auth::nivel() === 1 && Auth::clienteId() === null) return $model->byAdminUser((int)Auth::id());
        return $model->byCliente((int)$user['cliente_id']);
    }
}
