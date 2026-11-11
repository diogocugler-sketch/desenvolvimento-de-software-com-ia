<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

runTests([
    'salva um treino no banco e lê os mesmos dados' => function (): void {
        withTemporaryClient(function (PDO $pdo, int $clientId): void {
            $workout = [
                'done' => ['ter:a' => true],
                'loads' => ['ter:a' => '42.5'],
                'presenca' => ['2026-11-10' => true],
                'historico' => [],
                'sessaoAtiva' => null,
            ];
            $json = json_encode($workout, JSON_THROW_ON_ERROR);

            $save = $pdo->prepare(
                'INSERT INTO treinos_clientes (cliente_id, dados_json) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE dados_json = VALUES(dados_json)'
            );
            $save->execute([$clientId, $json]);

            $read = $pdo->prepare('SELECT dados_json FROM treinos_clientes WHERE cliente_id = ?');
            $read->execute([$clientId]);
            assertSameValue($workout, json_decode($read->fetchColumn(), true, 512, JSON_THROW_ON_ERROR));
        });
    },
    'salva várias séries e consulta-as no histórico' => function (): void {
        withTemporaryClient(function (PDO $pdo, int $clientId): void {
            $workout = [
                'done' => [],
                'loads' => [],
                'presenca' => [],
                'historico' => [[
                    'diaId' => 'ter',
                    'data' => '2026-11-10',
                    'iniciadoEm' => 1794340000000,
                    'finalizadoEm' => 1794343600000,
                    'exercicios' => [
                        ['nome' => 'Supino reto', 'series' => 3, 'repeticoes' => '10', 'carga' => '60', 'feito' => true],
                        ['nome' => 'Remada curvada', 'series' => 4, 'repeticoes' => '8-10', 'carga' => '50', 'feito' => true],
                    ],
                ]],
            ];
            $save = $pdo->prepare(
                'INSERT INTO treinos_clientes (cliente_id, dados_json) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE dados_json = VALUES(dados_json)'
            );
            $save->execute([$clientId, json_encode($workout, JSON_THROW_ON_ERROR)]);

            $read = $pdo->prepare('SELECT dados_json FROM treinos_clientes WHERE cliente_id = ?');
            $read->execute([$clientId]);
            $history = json_decode($read->fetchColumn(), true, 512, JSON_THROW_ON_ERROR)['historico'];
            assertSameValue(1, count($history));
            assertSameValue(2, count($history[0]['exercicios']));
            assertSameValue('50', $history[0]['exercicios'][1]['carga']);
        });
    },
    'lê uma lista de histórico vazia sem inventar sessões' => function (): void {
        withTemporaryClient(function (PDO $pdo, int $clientId): void {
            $emptyWorkout = ['done' => [], 'loads' => [], 'presenca' => [], 'historico' => []];
            $save = $pdo->prepare('INSERT INTO treinos_clientes (cliente_id, dados_json) VALUES (?, ?)');
            $save->execute([$clientId, json_encode($emptyWorkout, JSON_THROW_ON_ERROR)]);

            $read = $pdo->prepare('SELECT dados_json FROM treinos_clientes WHERE cliente_id = ?');
            $read->execute([$clientId]);
            $history = json_decode($read->fetchColumn(), true, 512, JSON_THROW_ON_ERROR)['historico'];
            assertSameValue([], $history);
        });
    },
]);
