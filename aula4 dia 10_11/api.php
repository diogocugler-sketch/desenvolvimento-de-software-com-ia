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

function focusNfeConfig(): array
{
    $environment = strtolower(trim((string) (getenv('FOCUS_NFE_ENVIRONMENT') ?: 'homologacao')));
    $token = trim((string) (getenv('FOCUS_NFE_TOKEN') ?: ''));
    $demoValue = strtolower(trim((string) (getenv('FOCUS_NFE_DEMO') ?: 'true')));
    $demo = $token === '' || !in_array($demoValue, ['0', 'false', 'no', 'off'], true);
    $baseUrl = $environment === 'producao'
        ? 'https://api.focusnfe.com.br/v2'
        : 'https://homologacao.focusnfe.com.br/v2';

    return ['configured' => $token !== '', 'demo' => $demo, 'environment' => $environment, 'base_url' => $baseUrl, 'token' => $token];
}

function focusNfeRequest(string $method, string $path, ?array $body = null): array
{
    $config = focusNfeConfig();
    if (!$config['configured']) {
        respond(503, ['error' => 'Configure FOCUS_NFE_TOKEN no servidor antes de emitir notas.']);
    }

    $curl = curl_init($config['base_url'] . $path);
    if ($curl === false) {
        respond(500, ['error' => 'Não foi possível iniciar a comunicação com a Focus NFe.']);
    }

    $headers = ['Accept: application/json'];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => $config['token'] . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($curl, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
    }

    $raw = curl_exec($curl);
    $httpStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (!is_string($raw) || $curlError !== '') {
        respond(502, ['error' => 'Falha de comunicação com a Focus NFe: ' . $curlError]);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $decoded = ['mensagem' => trim($raw)];
    }

    if ($httpStatus >= 400) {
        $message = $decoded['mensagem'] ?? $decoded['error'] ?? 'A Focus NFe recusou a operação.';
        respond($httpStatus === 401 || $httpStatus === 403 ? 502 : $httpStatus, ['error' => $message, 'details' => $decoded]);
    }

    return $decoded;
}

function cleanDocument(string $document): string
{
    return preg_replace('/\D/', '', $document) ?? '';
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

    if ($action === 'nfe_config' && $method === 'GET') {
        requireClient();
        $config = focusNfeConfig();
        respond(200, [
            'configured' => $config['configured'],
            'demo' => $config['demo'],
            'environment' => $config['environment'],
            'issuerConfigured' => $config['demo']
                || (trim((string) (getenv('FOCUS_NFE_CNPJ_EMITENTE') ?: '')) !== ''
                    && trim((string) (getenv('FOCUS_NFE_NOME_EMITENTE') ?: '')) !== ''),
        ]);
    }

    if ($action === 'nfe_list' && $method === 'GET') {
        $client = requireClient();
        $query = database()->prepare(
            'SELECT id, referencia, status, numero, chave, caminho_xml, caminho_danfe, mensagem, criado_em, atualizado_em
             FROM notas_fiscais WHERE cliente_id = ? ORDER BY id DESC LIMIT 100'
        );
        $query->execute([$client['id']]);
        respond(200, ['notes' => $query->fetchAll()]);
    }

    if ($action === 'nfe_consult' && $method === 'GET') {
        $client = requireClient();
        $reference = trim((string) ($_GET['ref'] ?? ''));
        if ($reference === '' || strlen($reference) > 120) {
            respond(422, ['error' => 'Informe uma referência válida.']);
        }

        $query = database()->prepare('SELECT id FROM notas_fiscais WHERE cliente_id = ? AND referencia = ?');
        $query->execute([$client['id'], $reference]);
        if (!$query->fetch()) {
            respond(404, ['error' => 'Nota fiscal não encontrada.']);
        }

        $config = focusNfeConfig();
        $result = $config['demo']
            ? [
                'ref' => $reference,
                'status' => 'autorizado',
                'status_sefaz' => '100',
                'mensagem_sefaz' => 'Autorizado o uso da NF-e — ambiente demonstrativo',
                'chave_nfe' => 'NFeEXEMPLO' . substr(str_pad(preg_replace('/\D/', '', $reference) ?? '0', 44, '0'), -44),
                'numero' => (string) random_int(1000, 9999),
                'ambiente' => 'demonstracao',
            ]
            : focusNfeRequest('GET', '/nfe/' . rawurlencode($reference) . '?completa=1');
        $update = database()->prepare(
            'UPDATE notas_fiscais SET status = ?, numero = ?, chave = ?, caminho_xml = ?, caminho_danfe = ?, mensagem = ?, resposta_json = ? WHERE cliente_id = ? AND referencia = ?'
        );
        $update->execute([
            (string) ($result['status'] ?? 'desconhecido'),
            isset($result['numero']) ? (string) $result['numero'] : null,
            isset($result['chave_nfe']) ? (string) $result['chave_nfe'] : null,
            isset($result['caminho_xml_nota_fiscal']) ? (string) $result['caminho_xml_nota_fiscal'] : null,
            isset($result['caminho_danfe']) ? (string) $result['caminho_danfe'] : null,
            isset($result['mensagem_sefaz']) ? (string) $result['mensagem_sefaz'] : null,
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $client['id'],
            $reference,
        ]);
        respond(200, ['note' => $result]);
    }

    if ($action === 'nfe_emit' && $method === 'POST') {
        $client = requireClient();
        $body = requestBody();

        $recipientDocument = cleanDocument((string) ($body['destinatario_documento'] ?? ''));
        $recipientName = trim((string) ($body['destinatario_nome'] ?? ''));
        $recipientEmail = strtolower(trim((string) ($body['destinatario_email'] ?? '')));
        $recipientCep = cleanDocument((string) ($body['destinatario_cep'] ?? ''));
        $recipientAddress = trim((string) ($body['destinatario_logradouro'] ?? ''));
        $recipientNumber = trim((string) ($body['destinatario_numero'] ?? ''));
        $recipientNeighborhood = trim((string) ($body['destinatario_bairro'] ?? ''));
        $recipientMunicipality = trim((string) ($body['destinatario_municipio'] ?? ''));
        $recipientUf = strtoupper(trim((string) ($body['destinatario_uf'] ?? '')));
        $itemDescription = trim((string) ($body['item_descricao'] ?? ''));
        $itemCode = trim((string) ($body['item_codigo'] ?? ''));
        $itemNcm = cleanDocument((string) ($body['item_ncm'] ?? ''));
        $itemCfop = cleanDocument((string) ($body['item_cfop'] ?? ''));
        $itemUnit = strtoupper(trim((string) ($body['item_unidade'] ?? 'UN')));
        $quantity = (float) ($body['item_quantidade'] ?? 0);
        $unitValue = (float) ($body['item_valor_unitario'] ?? 0);

        if (!in_array(strlen($recipientDocument), [11, 14], true)
            || strlen($recipientName) < 2 || strlen($recipientName) > 120
            || strlen($recipientCep) !== 8
            || $recipientAddress === '' || strlen($recipientAddress) > 120
            || $recipientNumber === '' || strlen($recipientNumber) > 20
            || $recipientNeighborhood === '' || strlen($recipientNeighborhood) > 120
            || $recipientMunicipality === '' || strlen($recipientMunicipality) > 120
            || !preg_match('/^[A-Z]{2}$/', $recipientUf)
            || $itemDescription === '' || strlen($itemDescription) > 120
            || $itemCode === '' || strlen($itemCode) > 60
            || strlen($itemNcm) !== 8 || strlen($itemCfop) !== 4
            || $itemUnit === '' || strlen($itemUnit) > 6
            || !is_finite($quantity) || $quantity <= 0
            || !is_finite($unitValue) || $unitValue <= 0
        ) {
            respond(422, ['error' => 'Preencha corretamente os dados do destinatário e do item da NF-e.']);
        }
        if ($recipientEmail !== '' && (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) || strlen($recipientEmail) > 190)) {
            respond(422, ['error' => 'Informe um e-mail válido para o destinatário.']);
        }

        $config = focusNfeConfig();
        $issuerCnpj = cleanDocument((string) (getenv('FOCUS_NFE_CNPJ_EMITENTE') ?: ''));
        $issuerName = trim((string) (getenv('FOCUS_NFE_NOME_EMITENTE') ?: ''));
        if (!$config['demo']) {
            if (strlen($issuerCnpj) !== 14 || $issuerName === '') {
                respond(503, ['error' => 'Configure FOCUS_NFE_CNPJ_EMITENTE e FOCUS_NFE_NOME_EMITENTE no servidor.']);
            }
        } else {
            $issuerCnpj = $issuerCnpj !== '' ? $issuerCnpj : '12345678000123';
            $issuerName = $issuerName !== '' ? $issuerName : 'Empresa Demonstrativa LTDA';
        }

        $reference = 'nfe-' . $client['id'] . '-' . bin2hex(random_bytes(8));
        $payload = [
            'natureza_operacao' => 'Venda de mercadoria',
            'data_emissao' => date('Y-m-d\TH:i:sP'),
            'tipo_documento' => '1',
            'finalidade_emissao' => '1',
            'cnpj_emitente' => $issuerCnpj,
            'nome_emitente' => $issuerName,
            'nome_fantasia_emitente' => trim((string) (getenv('FOCUS_NFE_NOME_FANTASIA_EMITENTE') ?: $issuerName)),
            'logradouro_emitente' => trim((string) (getenv('FOCUS_NFE_LOGRADOURO_EMITENTE') ?: '')),
            'numero_emitente' => trim((string) (getenv('FOCUS_NFE_NUMERO_EMITENTE') ?: '')),
            'bairro_emitente' => trim((string) (getenv('FOCUS_NFE_BAIRRO_EMITENTE') ?: '')),
            'municipio_emitente' => trim((string) (getenv('FOCUS_NFE_MUNICIPIO_EMITENTE') ?: '')),
            'uf_emitente' => strtoupper(trim((string) (getenv('FOCUS_NFE_UF_EMITENTE') ?: ''))),
            'cep_emitente' => cleanDocument((string) (getenv('FOCUS_NFE_CEP_EMITENTE') ?: '')),
            'telefone_emitente' => trim((string) (getenv('FOCUS_NFE_TELEFONE_EMITENTE') ?: '')),
            'inscricao_estadual_emitente' => trim((string) (getenv('FOCUS_NFE_IE_EMITENTE') ?: '')),
            'cpf_destinatario' => strlen($recipientDocument) === 11 ? $recipientDocument : null,
            'cnpj_destinatario' => strlen($recipientDocument) === 14 ? $recipientDocument : null,
            'nome_destinatario' => $recipientName,
            'indicador_inscricao_estadual_destinatario' => '9',
            'logradouro_destinatario' => $recipientAddress,
            'numero_destinatario' => $recipientNumber,
            'bairro_destinatario' => $recipientNeighborhood,
            'municipio_destinatario' => $recipientMunicipality,
            'uf_destinatario' => $recipientUf,
            'cep_destinatario' => $recipientCep,
            'email_destinatario' => $recipientEmail,
            'items' => [[
                'numero_item' => '1',
                'codigo_produto' => $itemCode,
                'descricao' => $itemDescription,
                'codigo_ncm' => $itemNcm,
                'cfop' => $itemCfop,
                'unidade_comercial' => $itemUnit,
                'quantidade_comercial' => number_format($quantity, 4, '.', ''),
                'valor_unitario_comercial' => number_format($unitValue, 10, '.', ''),
            ]],
        ];

        $payload = array_filter($payload, static fn($value): bool => $value !== null && $value !== '' && $value !== []);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            respond(500, ['error' => 'Não foi possível preparar os dados da nota.']);
        }

        $result = $config['demo']
            ? [
                'ref' => $reference,
                'status' => 'processando_autorizacao',
                'mensagem_sefaz' => 'Nota criada apenas para demonstração. Nenhum documento fiscal foi enviado à SEFAZ.',
                'ambiente' => 'demonstracao',
            ]
            : focusNfeRequest('POST', '/nfe?ref=' . rawurlencode($reference), $payload);
        $insert = database()->prepare(
            'INSERT INTO notas_fiscais
             (cliente_id, referencia, status, numero, chave, caminho_xml, caminho_danfe, mensagem, payload_json, resposta_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $client['id'],
            $reference,
            (string) ($result['status'] ?? 'processando_autorizacao'),
            isset($result['numero']) ? (string) $result['numero'] : null,
            isset($result['chave_nfe']) ? (string) $result['chave_nfe'] : null,
            isset($result['caminho_xml_nota_fiscal']) ? (string) $result['caminho_xml_nota_fiscal'] : null,
            isset($result['caminho_danfe']) ? (string) $result['caminho_danfe'] : null,
            isset($result['mensagem_sefaz']) ? (string) $result['mensagem_sefaz'] : null,
            $payloadJson,
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        respond(201, ['reference' => $reference, 'note' => $result]);
    }

    if (in_array($action, ['register', 'login', 'logout', 'me', 'workout', 'leaderboard', 'nfe_config', 'nfe_list', 'nfe_consult', 'nfe_emit'], true)) {
        header('Allow: ' . match ($action) {
            'register', 'login', 'logout', 'nfe_emit' => 'POST',
            'me', 'nfe_config', 'nfe_list', 'nfe_consult' => 'GET',
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
