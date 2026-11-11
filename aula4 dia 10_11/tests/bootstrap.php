<?php
declare(strict_types=1);

function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message ?: 'Esperado ' . var_export($expected, true) . ', recebido ' . var_export($actual, true));
    }
}

function assertNear(float $expected, float $actual, float $tolerance = 0.000001): void
{
    if (abs($expected - $actual) > $tolerance) {
        throw new RuntimeException("Esperado aproximadamente {$expected}, recebido {$actual}.");
    }
}

function assertThrows(callable $callback, string $message = 'Era esperado um erro.'): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $error) {
        return;
    }

    throw new RuntimeException($message);
}

function runTests(array $tests): void
{
    $passed = 0;
    $failed = 0;

    foreach ($tests as $name => $test) {
        try {
            $test();
            echo "[OK] {$name}" . PHP_EOL;
            $passed++;
        } catch (Throwable $error) {
            fwrite(STDERR, "[FALHOU] {$name}: {$error->getMessage()}" . PHP_EOL);
            $failed++;
        }
    }

    echo PHP_EOL . "Resultado: {$passed} passou(aram), {$failed} falhou(aram)." . PHP_EOL;
    if ($failed > 0) {
        exit(1);
    }
}

function testDatabase(): PDO
{
    require_once __DIR__ . '/../db.php';
    return database();
}

function withTemporaryClient(callable $test): void
{
    $pdo = testDatabase();
    $pdo->beginTransaction();

    try {
        $email = 'teste-' . bin2hex(random_bytes(8)) . '@example.invalid';
        $insert = $pdo->prepare(
            'INSERT INTO clientes (nome, email, senha_hash) VALUES (?, ?, ?)'
        );
        $insert->execute(['Conta temporária de teste', $email, password_hash('Teste123!', PASSWORD_DEFAULT)]);
        $clientId = (int) $pdo->lastInsertId();

        $test($pdo, $clientId);
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
