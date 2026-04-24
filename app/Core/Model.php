<?php
abstract class Model {
    protected PDO $db;
    protected string $table;

    public function __construct() {
        $this->db = Database::connect();
    }

    public function find(int $id): ?array {
        $st = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public function all(string $orderBy = 'id DESC'): array {
        return $this->db->query("SELECT * FROM {$this->table} ORDER BY {$orderBy}")->fetchAll();
    }

    public function insert(array $data): int {
        $cols = implode(',', array_keys($data));
        $phs  = implode(',', array_fill(0, count($data), '?'));
        $st   = $this->db->prepare("INSERT INTO {$this->table} ({$cols}) VALUES ({$phs})");
        $st->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void {
        $set = implode(',', array_map(fn($k) => "{$k}=?", array_keys($data)));
        $st  = $this->db->prepare("UPDATE {$this->table} SET {$set} WHERE id=?");
        $st->execute([...array_values($data), $id]);
    }

    public function delete(int $id): void {
        $st = $this->db->prepare("DELETE FROM {$this->table} WHERE id=?");
        $st->execute([$id]);
    }
}
