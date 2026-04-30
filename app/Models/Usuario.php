<?php
class Usuario extends Model {
    protected string $table = 'usuarios';

    public function findByEmail(string $email): ?array {
        $st = $this->db->prepare("SELECT * FROM usuarios WHERE email = ? AND ativo = 1");
        $st->execute([$email]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function byCliente(int $clienteId): array {
        $st = $this->db->prepare("SELECT * FROM usuarios WHERE cliente_id = ? AND ativo = 1 ORDER BY nome");
        $st->execute([$clienteId]);
        return $st->fetchAll();
    }

    /** Usuários do sistema (sem cliente): SuperAdmin + Administrador */
    public function allSistema(): array {
        $st = $this->db->prepare("SELECT * FROM usuarios WHERE cliente_id IS NULL AND ativo = 1 ORDER BY nivel, nome");
        $st->execute();
        return $st->fetchAll();
    }

    /** Administradores do sistema (nivel=1, sem cliente) — para atribuir a projetos */
    public function allAdministradores(): array {
        $st = $this->db->prepare("SELECT id, nome, email FROM usuarios WHERE nivel = 1 AND cliente_id IS NULL AND ativo = 1 ORDER BY nome");
        $st->execute();
        return $st->fetchAll();
    }

    public function createUser(string $nome, string $email, string $senha, int $nivel, ?int $clienteId): int {
        return $this->insert([
            'nome'       => $nome,
            'email'      => $email,
            'senha_hash' => password_hash($senha, PASSWORD_BCRYPT),
            'nivel'      => $nivel,
            'cliente_id' => $clienteId,
            'ativo'      => 1,
        ]);
    }

    public function verifyPassword(string $senha, string $hash): bool {
        return password_verify($senha, $hash);
    }

    public function levelLabel(int $nivel): string {
        return match($nivel) {
            0 => 'SuperAdmin',
            1 => 'ClienteAdmin',
            2 => 'Gestor',
            3 => 'Analista',
            4 => 'Visualizador',
            default => 'Desconhecido',
        };
    }
}
