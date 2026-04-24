<?php
class SentinelaArquivo extends Model {
    protected string $table = 'sentinela_arquivos';

    public function byProjeto(int $projetoId): array {
        $st = $this->db->prepare("
            SELECT * FROM sentinela_arquivos
            WHERE projeto_id = ? AND ativo = 1
            ORDER BY nome
        ");
        $st->execute([$projetoId]);
        return $st->fetchAll();
    }

    public function addArquivo(int $projetoId, string $nome, string $conteudo): int {
        return $this->insert([
            'projeto_id' => $projetoId,
            'nome'       => $nome,
            'conteudo'   => $conteudo,
            'ativo'      => 1,
            'criado_em'  => date('Y-m-d H:i:s'),
        ]);
    }

    public function remove(int $id): void {
        $this->update($id, ['ativo' => 0]);
    }
}
