<?php
/**
 * Teste unitário para Usuario::createUser
 * Usa SQLite em memória — não precisa de MySQL nem PHPUnit.
 * Rodar: php tests/test_create_user.php
 */

// ── Bootstrap mínimo ────────────────────────────────────────────────────────

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE usuarios (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    nome       TEXT NOT NULL,
    email      TEXT NOT NULL UNIQUE,
    senha_hash TEXT NOT NULL,
    nivel      INTEGER NOT NULL DEFAULT 2,
    cliente_id INTEGER,
    ativo      INTEGER NOT NULL DEFAULT 1
)");

class Database {
    private static PDO $conn;
    public static function setConn(PDO $p): void { self::$conn = $p; }
    public static function connect(): PDO { return self::$conn; }
}
Database::setConn($pdo);

abstract class Model {
    protected PDO $db;
    protected string $table;
    public function __construct() { $this->db = Database::connect(); }
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
}

require __DIR__ . '/../app/Models/Usuario.php';

// ── Helpers de assert ────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function assert_eq(string $label, mixed $expected, mixed $actual): void {
    global $passed, $failed;
    if ($expected === $actual) {
        echo "[PASS] {$label}\n";
        $passed++;
    } else {
        echo "[FAIL] {$label} — esperado " . var_export($expected, true) . ", obtido " . var_export($actual, true) . "\n";
        $failed++;
    }
}

function row(PDO $pdo, string $email): ?array {
    $st = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
    $st->execute([$email]);
    return $st->fetch() ?: null;
}

// ── Testes ───────────────────────────────────────────────────────────────────

$u = new Usuario();

// Caso 1: inserir usuário novo
$id1 = $u->createUser('João', 'joao@test.com', '123456', 2, null);
$r1  = row($pdo, 'joao@test.com');
assert_eq('Caso 1: retorna ID > 0',         true,      $id1 > 0);
assert_eq('Caso 1: nome correto',            'João',    $r1['nome']);
assert_eq('Caso 1: ativo = 1',              1,         (int)$r1['ativo']);
assert_eq('Caso 1: senha válida (bcrypt)',   true,      password_verify('123456', $r1['senha_hash']));

// Caso 2: recadastrar mesmo email (usuário ativo)
$id2 = $u->createUser('João Atualizado', 'joao@test.com', 'novaSenha', 1, null);
$r2  = row($pdo, 'joao@test.com');
assert_eq('Caso 2: retorna mesmo ID',        $id1,      $id2);
assert_eq('Caso 2: nome atualizado',         'João Atualizado', $r2['nome']);
assert_eq('Caso 2: nivel atualizado',        1,         (int)$r2['nivel']);
assert_eq('Caso 2: senha nova válida',       true,      password_verify('novaSenha', $r2['senha_hash']));

// Caso 3: recadastrar email de usuário soft-deletado (ativo = 0)
$pdo->exec("INSERT INTO usuarios (nome, email, senha_hash, nivel, ativo) VALUES ('Maria', 'maria@test.com', 'x', 2, 0)");
$idMaria = (int)$pdo->lastInsertId();
$id3 = $u->createUser('Maria Reativada', 'maria@test.com', 'senha123', 2, null);
$r3  = row($pdo, 'maria@test.com');
assert_eq('Caso 3: retorna ID existente',    $idMaria,  $id3);
assert_eq('Caso 3: nome atualizado',         'Maria Reativada', $r3['nome']);
assert_eq('Caso 3: ativo = 1 (reativado)',  1,         (int)$r3['ativo']);

// ── Resultado ────────────────────────────────────────────────────────────────

echo "\n{$passed} passou(ram), {$failed} falhou(aram).\n";
exit($failed > 0 ? 1 : 0);
