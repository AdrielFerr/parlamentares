<?php
class Crypto {
    private static function key(): string {
        return hex2bin(CRYPTO_KEY);
    }

    public static function encrypt(string $plain): string {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $cipher);
    }

    public static function decrypt(string $encoded): string {
        $raw = base64_decode($encoded);
        $iv  = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        return openssl_decrypt($cipher, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv);
    }
}
