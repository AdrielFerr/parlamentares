<?php
class Cliente extends Model {
    protected string $table = 'clientes';

    public function allAtivos(): array {
        return $this->db->query("SELECT * FROM clientes WHERE ativo = 1 ORDER BY nome")->fetchAll();
    }

    public function projetosCount(int $clienteId): int {
        $st = $this->db->prepare("SELECT COUNT(*) FROM projetos WHERE cliente_id = ?");
        $st->execute([$clienteId]);
        return (int) $st->fetchColumn();
    }
}
