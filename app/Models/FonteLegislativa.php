<?php
class FonteLegislativa extends Model {
    protected string $table = 'fontes_legislativas';

    public function allOrdered(): array {
        return $this->db->query("SELECT * FROM fontes_legislativas ORDER BY label")->fetchAll();
    }

    public function findByKey(string $key): ?array {
        $st = $this->db->prepare("SELECT * FROM fontes_legislativas WHERE source_key = ?");
        $st->execute([$key]);
        $row = $st->fetch();
        return $row ?: null;
    }
}
