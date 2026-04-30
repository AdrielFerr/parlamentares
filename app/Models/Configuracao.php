<?php
class Configuracao {

    private static bool $tableReady = false;

    private static function db(): PDO {
        return Database::connect();
    }

    private static function ensureTable(): void {
        if (self::$tableReady) return;
        self::db()->exec("
            CREATE TABLE IF NOT EXISTS configuracoes_sistema (
              id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              cliente_id INT UNSIGNED NULL,
              chave      VARCHAR(100) NOT NULL,
              valor      TEXT,
              UNIQUE KEY uq_cliente_chave (cliente_id, chave)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        self::$tableReady = true;
    }

    public static function get(string $chave, ?int $clienteId, string $default = ''): string {
        self::ensureTable();
        $db = self::db();
        if ($clienteId !== null) {
            $st = $db->prepare("SELECT valor FROM configuracoes_sistema WHERE chave=? AND cliente_id=? LIMIT 1");
            $st->execute([$chave, $clienteId]);
            $row = $st->fetch();
            if ($row) return $row['valor'] ?? $default;
        }
        $st = $db->prepare("SELECT valor FROM configuracoes_sistema WHERE chave=? AND cliente_id IS NULL LIMIT 1");
        $st->execute([$chave]);
        $row = $st->fetch();
        return $row ? ($row['valor'] ?? $default) : $default;
    }

    public static function set(string $chave, string $valor, ?int $clienteId): void {
        self::ensureTable();
        $db = self::db();
        if ($clienteId !== null) {
            $st = $db->prepare("INSERT INTO configuracoes_sistema (cliente_id,chave,valor) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
            $st->execute([$clienteId, $chave, $valor]);
        } else {
            // MySQL permite múltiplos NULLs em UNIQUE KEY — usamos delete+insert
            $db->prepare("DELETE FROM configuracoes_sistema WHERE chave=? AND cliente_id IS NULL")
               ->execute([$chave]);
            $db->prepare("INSERT INTO configuracoes_sistema (cliente_id,chave,valor) VALUES (NULL,?,?)")
               ->execute([$chave, $valor]);
        }
    }

    public static function forCliente(?int $clienteId): array {
        self::ensureTable();
        $db      = self::db();
        $global  = [];
        $specific = [];

        $st = $db->prepare("SELECT chave,valor FROM configuracoes_sistema WHERE cliente_id IS NULL");
        $st->execute();
        foreach ($st->fetchAll() as $row) $global[$row['chave']] = $row['valor'];

        if ($clienteId !== null) {
            $st = $db->prepare("SELECT chave,valor FROM configuracoes_sistema WHERE cliente_id=?");
            $st->execute([$clienteId]);
            foreach ($st->fetchAll() as $row) $specific[$row['chave']] = $row['valor'];
        }

        return array_merge($global, $specific);
    }

    // ─── Derivação de cores ───────────────────────────────────────────────────

    public static function isValidHex(string $hex): bool {
        return (bool) preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hex);
    }

    private static function hexToRgb(string $hex): array {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
    }

    private static function rgbToHex(int $r, int $g, int $b): string {
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    public static function darken(string $hex, float $amount = 0.15): string {
        if (!self::isValidHex($hex)) return $hex;
        [$r,$g,$b] = self::hexToRgb($hex);
        $factor = 1 - $amount;
        return self::rgbToHex(
            (int) round($r * $factor),
            (int) round($g * $factor),
            (int) round($b * $factor)
        );
    }

    public static function lighten(string $hex, float $mix = 0.1): string {
        if (!self::isValidHex($hex)) return $hex;
        [$r,$g,$b] = self::hexToRgb($hex);
        // Mistura com branco
        $factor = 1 - $mix;
        return self::rgbToHex(
            (int) round($r * $factor + 255 * $mix),
            (int) round($g * $factor + 255 * $mix),
            (int) round($b * $factor + 255 * $mix)
        );
    }

    // Variante bem clara para backgrounds (accent-light)
    public static function accentLight(string $hex): string {
        return self::lighten($hex, 0.88);
    }

    // ─── CSS vars prontas para <style> ───────────────────────────────────────

    public static function getCssVars(?int $clienteId): string {
        try {
            $cfg = self::forCliente($clienteId);
        } catch (\Throwable $e) {
            return '';
        }

        $accent = $cfg['cor_accent'] ?? '';
        if (!self::isValidHex($accent)) return '';

        $dark  = self::darken($accent, 0.12);
        $light = self::accentLight($accent);

        return "--accent:{$accent};--accent-dark:{$dark};--accent-light:{$light};";
    }

    // ─── Logo ─────────────────────────────────────────────────────────────────

    public static function logoUrl(?int $clienteId): string {
        try {
            $url = self::get('logo_url', $clienteId, '');
            if ($url && is_file(ROOT . $url)) return $url;

            // Fallback: percorre todos os registros de logo e retorna o primeiro cujo arquivo existe
            self::ensureTable();
            $st = self::db()->prepare(
                "SELECT valor FROM configuracoes_sistema WHERE chave='logo_url' AND valor IS NOT NULL AND valor != '' ORDER BY cliente_id IS NULL DESC"
            );
            $st->execute();
            foreach ($st->fetchAll() as $row) {
                $candidate = $row['valor'] ?? '';
                if ($candidate && is_file(ROOT . $candidate)) return $candidate;
            }
            return '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
