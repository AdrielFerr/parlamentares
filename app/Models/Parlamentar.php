<?php
class Parlamentar extends Model {
    protected string $table = 'parlamentares_cache';

    public function findBySourceAndId(string $source, int $saplId): ?array {
        $st = $this->db->prepare("SELECT * FROM parlamentares_cache WHERE source_key = ? AND sapl_id = ?");
        $st->execute([$source, $saplId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function upsert(string $source, int $saplId, array $dados): void {
        $existing = $this->findBySourceAndId($source, $saplId);
        $json = json_encode($dados, JSON_UNESCAPED_UNICODE);
        if ($existing) {
            $this->update($existing['id'], ['dados_json' => $json, 'atualizado_em' => date('Y-m-d H:i:s')]);
        } else {
            $this->insert([
                'source_key'   => $source,
                'sapl_id'      => $saplId,
                'dados_json'   => $json,
                'atualizado_em'=> date('Y-m-d H:i:s'),
            ]);
        }
    }
}
