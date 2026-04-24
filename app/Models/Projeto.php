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

    /* ── Verifica se usuário pode acessar o projeto ── */
    public function canAccess(int $projetoId, int $userId, int $nivel, ?int $clienteId): bool {
        if ($nivel === 0) return true;
        $st = $this->db->prepare("
            SELECT p.id FROM projetos p
            WHERE p.id = ? AND p.cliente_id = ? AND p.ativo = 1
        ");
        $st->execute([$projetoId, $clienteId]);
        return (bool)$st->fetch();
    }
}
