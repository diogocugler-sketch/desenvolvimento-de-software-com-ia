<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

runTests([
    'cria treino, registra série, finaliza e encontra a sessão no histórico' => function (): void {
        withTemporaryClient(function (PDO $pdo, int $clientId): void {
            $startedAt = (int) floor(microtime(true) * 1000);
            $workout = [
                'done' => [],
                'loads' => [],
                'presenca' => [],
                'historico' => [],
                'sessaoAtiva' => [
                    'diaId' => 'ter',
                    'data' => date('Y-m-d'),
                    'iniciadoEm' => $startedAt,
                    'concluidos' => [],
                ],
            ];

            $exercise = ['nome' => 'Supino reto', 'series' => 3, 'repeticoes' => '10', 'carga' => '60', 'feito' => true];
            $workout['sessaoAtiva']['concluidos'][] = 'ter:a';
            $workout['done']['ter:a'] = true;
            $workout['loads']['ter:a'] = $exercise['carga'];
            $finishedAt = $startedAt + 1_000;
            $workout['historico'][] = [
                'diaId' => 'ter',
                'data' => date('Y-m-d'),
                'iniciadoEm' => $startedAt,
                'finalizadoEm' => $finishedAt,
                'exercicios' => [$exercise],
            ];
            $workout['sessaoAtiva'] = null;

            $save = $pdo->prepare(
                'INSERT INTO treinos_clientes (cliente_id, dados_json) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE dados_json = VALUES(dados_json)'
            );
            $save->execute([$clientId, json_encode($workout, JSON_THROW_ON_ERROR)]);

            $read = $pdo->prepare('SELECT dados_json FROM treinos_clientes WHERE cliente_id = ?');
            $read->execute([$clientId]);
            $saved = json_decode($read->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);

            assertSameValue(null, $saved['sessaoAtiva']);
            assertSameValue(1, count($saved['historico']));
            assertSameValue('Supino reto', $saved['historico'][0]['exercicios'][0]['nome']);
            assertSameValue('60', $saved['historico'][0]['exercicios'][0]['carga']);
            assertSameValue(true, $saved['historico'][0]['exercicios'][0]['feito']);
            assertSameValue(1_000, $saved['historico'][0]['finalizadoEm'] - $saved['historico'][0]['iniciadoEm']);
        });
    },
]);
