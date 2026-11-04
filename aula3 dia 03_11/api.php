<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        respond(400, ['error' => 'Envie os dados em formato JSON válido.']);
    }

    return $data;
}

function currentClient(): ?array
{
    if (!isset($_SESSION['client_id'])) {
        return null;
    }

    $query = database()->prepare('SELECT id, nome, email FROM clientes WHERE id = ?');
    $query->execute([$_SESSION['client_id']]);
    $client = $query->fetch();
    if (!$client) {
        unset($_SESSION['client_id']);
        return null;
    }

    return [
        'id' => (int) $client['id'],
        'nome' => $client['nome'],
        'email' => $client['email'],
    ];
}

function requireClient(): array
{
    $client = currentClient();
    if ($client === null) {
        respond(401, ['error' => 'Faça login para continuar.']);
    }

    return $client;
}

function finishLogin(array $client): void
{
    session_regenerate_id(true);
    $_SESSION['client_id'] = (int) $client['id'];
    respond(200, [
        'user' => [
            'id' => (int) $client['id'],
            'nome' => $client['nome'],
            'email' => $client['email'],
        ],
    ]);
}

function validateMap(array $data, string $key, string $valueType): array
{
    if (!isset($data[$key]) || !is_array($data[$key]) || count($data[$key]) > 2000) {
        respond(400, ['error' => 'Os dados do treino enviados são inválidos.']);
    }

    foreach ($data[$key] as $entryKey => $value) {
        if (!is_string($entryKey) || strlen($entryKey) > 80) {
            respond(400, ['error' => 'Há um registro de treino inválido.']);
        }

        if ($valueType === 'boolean' && !is_bool($value)) {
            respond(400, ['error' => 'Há um registro de presença inválido.']);
        }
        if ($valueType === 'string' && (!is_string($value) || strlen($value) > 30)) {
            respond(400, ['error' => 'Há uma carga de exercício inválida.']);
        }
    }

    return $data[$key];
}

try {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'register' && $method === 'POST') {
        $body = requestBody();
        $name = trim((string) ($body['nome'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['senha'] ?? '');

        if (strlen($name) < 2 || strlen($name) > 120) {
            respond(422, ['error' => 'Informe um nome com pelo menos 2 caracteres.']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            respond(422, ['error' => 'Informe um e-mail válido.']);
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            respond(422, ['error' => 'A senha deve ter entre 8 e 72 caracteres.']);
        }

        try {
            $query = database()->prepare(
                'INSERT INTO clientes (nome, email, senha_hash) VALUES (?, ?, ?)'
            );
            $query->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                respond(409, ['error' => 'Este e-mail já possui cadastro.']);
            }
            throw $error;
        }

        finishLogin([
            'id' => (int) database()->lastInsertId(),
            'nome' => $name,
            'email' => $email,
        ]);
    }

    if ($action === 'login' && $method === 'POST') {
        $body = requestBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['senha'] ?? '');
        $query = database()->prepare(
            'SELECT id, nome, email, senha_hash FROM clientes WHERE email = ?'
        );
        $query->execute([$email]);
        $client = $query->fetch();

        if (!$client || !password_verify($password, $client['senha_hash'])) {
            respond(401, ['error' => 'E-mail ou senha incorretos.']);
        }
        if (password_needs_rehash($client['senha_hash'], PASSWORD_DEFAULT)) {
            $update = database()->prepare('UPDATE clientes SET senha_hash = ? WHERE id = ?');
            $update->execute([password_hash($password, PASSWORD_DEFAULT), $client['id']]);
        }

        finishLogin($client);
    }

    if ($action === 'me' && $method === 'GET') {
        $client = currentClient();
        if ($client === null) {
            respond(401, ['error' => 'Faça login para continuar.']);
        }
        respond(200, ['user' => $client]);
    }

    if ($action === 'logout' && $method === 'POST') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        respond(200, ['message' => 'Sessão encerrada.']);
    }

    if ($action === 'workout' && $method === 'GET') {
        $client = requireClient();
        $query = database()->prepare(
            'SELECT dados_json FROM treinos_clientes WHERE cliente_id = ?'
        );
        $query->execute([$client['id']]);
        $saved = $query->fetchColumn();
        $data = $saved === false ? null : json_decode($saved, true);
        if (!is_array($data)) {
            $data = ['done' => [], 'loads' => [], 'presenca' => []];
        }
        respond(200, ['data' => $data]);
    }

    if ($action === 'workout' && $method === 'PUT') {
        $client = requireClient();
        $body = requestBody();
        $done = validateMap($body, 'done', 'boolean');
        $loads = validateMap($body, 'loads', 'string');
        $attendance = validateMap($body, 'presenca', 'boolean');
        $json = json_encode(
            ['done' => $done, 'loads' => $loads, 'presenca' => $attendance],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false || strlen($json) > 1000000) {
            respond(413, ['error' => 'Os dados do treino excedem o limite permitido.']);
        }

        $query = database()->prepare(
            'INSERT INTO treinos_clientes (cliente_id, dados_json) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE dados_json = VALUES(dados_json)'
        );
        $query->execute([$client['id'], $json]);
        respond(200, ['message' => 'Treino sincronizado.']);
    }

    if (in_array($action, ['register', 'login', 'logout', 'me', 'workout'], true)) {
        header('Allow: ' . match ($action) {
            'register', 'login', 'logout' => 'POST',
            'me' => 'GET',
            'workout' => 'GET, PUT',
        });
        respond(405, ['error' => 'Método não permitido.']);
    }

    respond(404, ['error' => 'Rota não encontrada.']);
} catch (Throwable $error) {
    error_log((string) $error);
    respond(500, ['error' => 'Não foi possível concluir a operação. Verifique a configuração do servidor.']);
}
