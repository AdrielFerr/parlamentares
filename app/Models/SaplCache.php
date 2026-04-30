<?php
class SaplCache {
    private static function db(): PDO { return Database::connect(); }

    public static function get(string $source, string $key): ?string {
        $st = self::db()->prepare(
            "SELECT data FROM sapl_cache
             WHERE source=? AND cache_key=? AND expires_at > NOW()
             LIMIT 1"
        );
        $st->execute([$source, $key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['data'] : null;
    }

    public static function set(string $source, string $key, string $data, int $ttlHours = 24): void {
        self::db()->prepare(
            "INSERT INTO sapl_cache (source, cache_key, data, expires_at)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))
             ON DUPLICATE KEY UPDATE
               data       = VALUES(data),
               expires_at = VALUES(expires_at),
               updated_at = NOW()"
        )->execute([$source, $key, $data, $ttlHours]);
    }

    public static function invalidate(string $source): int {
        $st = self::db()->prepare("DELETE FROM sapl_cache WHERE source=?");
        $st->execute([$source]);
        return $st->rowCount();
    }

    public static function stats(string $source): array {
        $st = self::db()->prepare(
            "SELECT
               COUNT(*)                                               AS total,
               SUM(CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END)   AS validos,
               MAX(updated_at)                                        AS ultima_atualizacao
             FROM sapl_cache WHERE source=?"
        );
        $st->execute([$source]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'validos' => 0, 'ultima_atualizacao' => null];
    }

    public static function getByPrefix(string $source, string $prefix): array {
        $st = self::db()->prepare(
            "SELECT data FROM sapl_cache
             WHERE source=? AND cache_key LIKE ? AND expires_at > NOW()
             ORDER BY CAST(SUBSTRING_INDEX(cache_key, 'page=', -1) AS UNSIGNED)"
        );
        $st->execute([$source, $prefix . '%']);
        return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public static function ttlFor(string $path): int {
        if (str_contains($path, '/parlamentares/legislatura')) return 168; // 7 dias
        if (str_contains($path, '/parlamentares/partido'))     return 168;
        if (str_contains($path, '/parlamentares/frente'))      return 72;  // frentes e frenteparlamentar
        if (str_contains($path, '/parlamentares/frentecargo')) return 168;
        if (str_contains($path, '/parlamentares/parlamentar')) return 24;
        if (str_contains($path, '/parlamentares/mandato'))     return 72;
        if (str_contains($path, '/parlamentares/filiacao'))    return 72;
        if (str_contains($path, '/base/autor'))                return 48;
        if (str_contains($path, '/comissoes/'))                return 48;
        if (str_contains($path, '/materia/relatoria'))         return 48;
        if (str_contains($path, '/materia/'))                  return 12;
        if (str_contains($path, '/norma/tiponorma'))           return 168;
        if (str_contains($path, '/norma/'))                    return 24;
        return 24;
    }
}
