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
        if (
            $valueType === 'string'
            && $value !== ''
            && (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0)
        ) {
            respond(400, ['error' => 'A carga deve ser um número igual ou maior que zero.']);
        }
    }

    return $data[$key];
}

function validWorkoutDate($date): bool
{
    if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
        return false;
    }

    return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
}

function validTimestamp($timestamp): bool
{
    return is_int($timestamp) && $timestamp >= 0;
}

function validateHistory(array $data): array
{
    $history = $data['historico'] ?? [];
    $weekdays = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
    if (!is_array($history) || count($history) > 2500) {
        respond(400, ['error' => 'O histórico de treinos enviado é inválido.']);
    }

    foreach ($history as $session) {
        if (
            !is_array($session)
            || !in_array($session['diaId'] ?? null, $weekdays, true)
            || !validWorkoutDate($session['data'] ?? null)
            || !validTimestamp($session['iniciadoEm'] ?? null)
            || !validTimestamp($session['finalizadoEm'] ?? null)
            || $session['finalizadoEm'] < $session['iniciadoEm']
            || !isset($session['exercicios'])
            || !is_array($session['exercicios'])
            || count($session['exercicios']) > 30
        ) {
            respond(400, ['error' => 'Há uma sessão inválida no histórico de treinos.']);
        }

        foreach ($session['exercicios'] as $exercise) {
            if (
                !is_array($exercise)
                || !is_string($exercise['nome'] ?? null)
                || strlen($exercise['nome']) > 120
                || !is_int($exercise['series'] ?? null)
                || $exercise['series'] < 1
                || $exercise['series'] > 100
                || !is_string($exercise['repeticoes'] ?? null)
                || strlen($exercise['repeticoes']) > 30
                || !is_string($exercise['carga'] ?? null)
                || strlen($exercise['carga']) > 30
                || !is_bool($exercise['feito'] ?? null)
            ) {
                respond(400, ['error' => 'Há um exercício inválido no histórico de treinos.']);
            }
        }
    }

    return $history;
}

function validateActiveWorkout(array $data): ?array
{
    $session = $data['sessaoAtiva'] ?? null;
    if ($session === null) {
        return null;
    }

    $weekdays = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
    if (
        !is_array($session)
        || !in_array($session['diaId'] ?? null, $weekdays, true)
        || !validWorkoutDate($session['data'] ?? null)
        || !validTimestamp($session['iniciadoEm'] ?? null)
        || !isset($session['concluidos'])
        || !is_array($session['concluidos'])
        || count($session['concluidos']) > 30
    ) {
        respond(400, ['error' => 'A sessão de treino em andamento é inválida.']);
    }

    foreach ($session['concluidos'] as $exerciseKey) {
        if (!is_string($exerciseKey) || strlen($exerciseKey) > 80) {
            respond(400, ['error' => 'Há um exercício inválido na sessão em andamento.']);
        }
    }

    return $session;
}

function validateWorkoutPlan(array $data): array
{
    $plan = $data['exercisesByDay'] ?? null;
    $weekdays = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
    if (!is_array($plan) || count($plan) > count($weekdays)) {
        respond(400, ['error' => 'A rotina semanal enviada é inválida.']);
    }

    foreach ($plan as $dayId => $exercises) {
        if (!in_array($dayId, $weekdays, true) || !is_array($exercises) || count($exercises) > 30) {
            respond(400, ['error' => 'Há um dia inválido na rotina semanal.']);
        }

        $ids = [];
        foreach ($exercises as $exercise) {
            if (
                !is_array($exercise)
                || !is_string($exercise['id'] ?? null)
                || $exercise['id'] === ''
                || strlen($exercise['id']) > 80
                || in_array($exercise['id'], $ids, true)
                || !is_string($exercise['name'] ?? null)
                || trim($exercise['name']) === ''
                || strlen($exercise['name']) > 120
                || !is_int($exercise['sets'] ?? null)
                || $exercise['sets'] < 1
                || $exercise['sets'] > 100
                || !is_string($exercise['reps'] ?? null)
                || trim($exercise['reps']) === ''
                || strlen($exercise['reps']) > 30
                || (isset($exercise['prev']) && (
                    !is_numeric($exercise['prev'])
                    || !is_finite((float) $exercise['prev'])
                    || (float) $exercise['prev'] < 0
                    || (float) $exercise['prev'] > 10000
                ))
            ) {
                respond(400, ['error' => 'Há um exercício inválido na rotina semanal.']);
            }
            $ids[] = $exercise['id'];
        }
    }

    return $plan;
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

    if ($action === 'leaderboard' && $method === 'GET') {
        requireClient();
        $query = database()->query(
            'SELECT c.id, c.nome, t.dados_json
             FROM clientes c
             LEFT JOIN treinos_clientes t ON t.cliente_id = c.id'
        );
        $rankings = ['treinos' => [], 'oneRm' => [], 'volume' => []];

        foreach ($query->fetchAll() as $row) {
            $data = $row['dados_json'] === null ? [] : json_decode($row['dados_json'], true);
            $history = is_array($data) && isset($data['historico']) && is_array($data['historico'])
                ? $data['historico']
                : [];
            $highestOneRm = 0.0;
            $recordExercise = null;
            $totalVolume = 0.0;

            foreach ($history as $session) {
                if (!isset($session['exercicios']) || !is_array($session['exercicios'])) {
                    continue;
                }

                foreach ($session['exercicios'] as $exercise) {
                    if (
                        !is_array($exercise)
                        || ($exercise['feito'] ?? false) !== true
                        || !is_numeric($exercise['carga'] ?? null)
                        || !is_numeric($exercise['series'] ?? null)
                        || !is_string($exercise['repeticoes'] ?? null)
                        || !preg_match('/^(\d+(?:\.\d+)?)(?:\s*-\s*(\d+(?:\.\d+)?))?$/', trim($exercise['repeticoes']), $matches)
                    ) {
                        continue;
                    }

                    $load = (float) $exercise['carga'];
                    $sets = (float) $exercise['series'];
                    $reps = (float) (($matches[2] ?? '') !== '' ? $matches[2] : $matches[1]);
                    if (
                        !is_finite($load)
                        || !is_finite($sets)
                        || !is_finite($reps)
                        || $load < 0
                        || $sets <= 0
                        || $reps <= 0
                    ) {
                        continue;
                    }

                    $totalVolume += $sets * $reps * $load;
                    $oneRm = $load * (1 + $reps / 30);
                    if ($oneRm > $highestOneRm) {
                        $highestOneRm = $oneRm;
                        $recordExercise = is_string($exercise['nome'] ?? null) ? $exercise['nome'] : null;
                    }
                }
            }

            $entry = ['id' => (int) $row['id'], 'nome' => $row['nome']];
            $rankings['treinos'][] = $entry + ['valor' => count($history)];
            $rankings['oneRm'][] = $entry + ['valor' => round($highestOneRm, 2), 'exercicio' => $recordExercise];
            $rankings['volume'][] = $entry + ['valor' => round($totalVolume, 2)];
        }

        foreach ($rankings as &$ranking) {
            usort($ranking, static function (array $left, array $right): int {
                $byValue = $right['valor'] <=> $left['valor'];
                return $byValue !== 0 ? $byValue : strcasecmp($left['nome'], $right['nome']);
            });
        }
        unset($ranking);

        respond(200, ['rankings' => $rankings]);
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
            $data = ['done' => [], 'loads' => [], 'presenca' => [], 'historico' => [], 'sessaoAtiva' => null];
        } else {
            $data['historico'] = $data['historico'] ?? [];
            $data['sessaoAtiva'] = $data['sessaoAtiva'] ?? null;
            $data['exercisesByDay'] = $data['exercisesByDay'] ?? null;
        }
        respond(200, ['data' => $data]);
    }

    if ($action === 'workout' && $method === 'PUT') {
        $client = requireClient();
        $body = requestBody();
        $done = validateMap($body, 'done', 'boolean');
        $loads = validateMap($body, 'loads', 'string');
        $attendance = validateMap($body, 'presenca', 'boolean');
        $history = validateHistory($body);
        $activeWorkout = validateActiveWorkout($body);
        $workoutPlan = validateWorkoutPlan($body);
        $json = json_encode(
            [
                'done' => $done,
                'loads' => $loads,
                'presenca' => $attendance,
                'historico' => $history,
                'sessaoAtiva' => $activeWorkout,
                'exercisesByDay' => $workoutPlan,
            ],
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

    if (in_array($action, ['register', 'login', 'logout', 'me', 'workout', 'leaderboard'], true)) {
        header('Allow: ' . match ($action) {
            'register', 'login', 'logout' => 'POST',
            'me' => 'GET',
            'workout' => 'GET, PUT',
            'leaderboard' => 'GET',
        });
        respond(405, ['error' => 'Método não permitido.']);
    }

    respond(404, ['error' => 'Rota não encontrada.']);
} catch (Throwable $error) {
    error_log((string) $error);
    respond(500, ['error' => 'Não foi possível concluir a operação. Verifique a configuração do servidor.']);
}
