<?php
class Projeto extends Model {
    protected string $table = 'projetos';

    /* ── Listagem por cliente (com fonte e contagem) ── */
    public function byCliente(int $clienteId): array {
        $st = $this->db->prepare("
            SELECT p.*, f.label AS fonte_label, f.source_key, f.url AS fonte_url
            FROM projetos p
            LEFT JOIN fontes_legislativas f ON f.id = p.fonte_id
            WHERE p.cliente_id = ? AND p.ativo = 1
            ORDER BY p.nome
        ");
        $st->execute([$clienteId]);
        return $st->fetchAll();
    }

    /* ── Listagem com cliente (Super Admin) ── */
    public function allWithCliente(): array {
        return $this->db->query("
            SELECT p.*, c.nome AS cliente_nome, f.label AS fonte_label, f.source_key, f.url AS fonte_url
            FROM projetos p
            LEFT JOIN clientes c ON c.id = p.cliente_id
            LEFT JOIN fontes_legislativas f ON f.id = p.fonte_id
            WHERE p.ativo = 1
            ORDER BY c.nome, p.nome
        ")->fetchAll();
    }

    /* ── Busca projeto com dados da fonte ── */
    public function findComFonte(int $id): ?array {
        $st = $this->db->prepare("
            SELECT p.*, f.label AS fonte_label, f.source_key, f.url AS fonte_url
            FROM projetos p
            LEFT JOIN fontes_legislativas f ON f.id = p.fonte_id
            WHERE p.id = ? AND p.ativo = 1
        ");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /* ── Conta parlamentares no cache para a source_key do projeto ── */
    public function countParlamentares(string $sourceKey): int {
        if (!$sourceKey) return 0;
        try {
            $st = $this->db->prepare(
                "SELECT COUNT(*) FROM parlamentares_cache WHERE source_key = ?"
            );
            $st->execute([$sourceKey]);
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* ── Chave OpenAI (decrypt) ── */
    public function getApiKey(int $projetoId): string {
        $st = $this->db->prepare("SELECT openai_key_enc FROM projetos WHERE id = ?");
        $st->execute([$projetoId]);
        $enc = $st->fetchColumn();
        if (!$enc) return '';
        return Crypto::decrypt($enc);
    }

    /* ── Chave OpenAI (encrypt + save) ── */
    public function setApiKey(int $projetoId, string $plainKey): void {
        $enc = Crypto::encrypt($plainKey);
        $this->update($projetoId, ['openai_key_enc' => $enc]);
    }

    /* ── Tabela de vínculos projeto ↔ administrador ── */
    private function ensureAdminsTable(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS projeto_admins (
                projeto_id INT UNSIGNED NOT NULL,
                usuario_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (projeto_id, usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /** Projetos atribuídos a um Administrador do sistema */
    public function byAdminUser(int $userId): array {
        $this->ensureAdminsTable();
        $st = $this->db->prepare("
            SELECT p.*, f.label AS fonte_label, f.source_key, f.url AS fonte_url
            FROM projetos p
            INNER JOIN projeto_admins pa ON pa.projeto_id = p.id
            LEFT JOIN fontes_legislativas f ON f.id = p.fonte_id
            WHERE pa.usuario_id = ? AND p.ativo = 1
            ORDER BY p.nome
        ");
        $st->execute([$userId]);
        return $st->fetchAll();
    }

    /** IDs dos administradores vinculados ao projeto */
    public function getAdminIds(int $projetoId): array {
        $this->ensureAdminsTable();
        $st = $this->db->prepare("SELECT usuario_id FROM projeto_admins WHERE projeto_id = ?");
        $st->execute([$projetoId]);
        return array_column($st->fetchAll(), 'usuario_id');
    }

    /** Define os administradores do projeto (substitui lista anterior) */
    public function setAdmins(int $projetoId, array $userIds): void {
        $this->ensureAdminsTable();
        $this->db->prepare("DELETE FROM projeto_admins WHERE projeto_id = ?")->execute([$projetoId]);
        $st = $this->db->prepare("INSERT IGNORE INTO projeto_admins (projeto_id, usuario_id) VALUES (?, ?)");
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $uid) {
            $st->execute([$projetoId, $uid]);
        }
    }

    /* ── Verifica se usuário pode acessar o projeto ── */
    public function canAccess(int $projetoId, int $userId, int $nivel, ?int $clienteId): bool {
        if ($nivel === 0) return true;
        if ($clienteId !== null) {
            $st = $this->db->prepare("SELECT id FROM projetos WHERE id = ? AND cliente_id = ? AND ativo = 1");
            $st->execute([$projetoId, $clienteId]);
            return (bool)$st->fetch();
        }
        // Administrador do sistema (nivel=1, sem cliente): verifica projeto_admins
        $this->ensureAdminsTable();
        $st = $this->db->prepare("SELECT 1 FROM projeto_admins WHERE projeto_id = ? AND usuario_id = ?");
        $st->execute([$projetoId, $userId]);
        return (bool)$st->fetch();
    }
}
