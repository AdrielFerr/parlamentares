<?php
class SentinelaConversa extends Model {
    protected string $table = 'sentinela_conversas';

    public function byProjeto(int $projetoId, int $limit = 50): array {
        $st = $this->db->prepare("
            SELECT * FROM sentinela_conversas
            WHERE projeto_id = ?
            ORDER BY criado_em DESC
            LIMIT ?
        ");
        $st->execute([$projetoId, $limit]);
        return $st->fetchAll();
    }

    public function save(int $projetoId, int $usuarioId, string $pergunta, string $resposta): int {
        return $this->insert([
            'projeto_id'  => $projetoId,
            'usuario_id'  => $usuarioId,
            'pergunta'    => $pergunta,
            'resposta'    => $resposta,
            'criado_em'   => date('Y-m-d H:i:s'),
        ]);
    }
}
