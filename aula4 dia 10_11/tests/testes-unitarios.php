<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function workoutVolume(mixed $sets, mixed $reps, mixed $load): float
{
    validateWorkoutValues($sets, $reps, $load);
    return (float) $sets * (float) $reps * (float) $load;
}

function estimatedOneRepMax(mixed $load, mixed $reps): float
{
    validateWorkoutValues(1, $reps, $load);
    return (float) $load * (1 + (float) $reps / 30);
}

function suggestNextLoad(mixed $load, array $repsPerSet, mixed $repTop, mixed $increment = 2.5): float
{
    validateWorkoutValues(1, $repTop, $load);
    if ($increment === '' || !is_numeric($increment) || (float) $increment <= 0) {
        throw new InvalidArgumentException('O aumento de carga deve ser maior que zero.');
    }
    if ($repsPerSet === []) {
        throw new InvalidArgumentException('Registre pelo menos uma série.');
    }

    foreach ($repsPerSet as $reps) {
        validateWorkoutValues(1, $reps, $load);
    }

    return min($repsPerSet) >= (float) $repTop
        ? (float) $load + (float) $increment
        : (float) $load;
}

function kilogramsToPounds(mixed $kilograms): float
{
    if ($kilograms === '' || !is_numeric($kilograms) || (float) $kilograms < 0) {
        throw new InvalidArgumentException('Informe uma carga válida em kg.');
    }
    return (float) $kilograms * 2.2046226218;
}

function poundsToKilograms(mixed $pounds): float
{
    if ($pounds === '' || !is_numeric($pounds) || (float) $pounds < 0) {
        throw new InvalidArgumentException('Informe uma carga válida em lb.');
    }
    return (float) $pounds / 2.2046226218;
}

function isPersonalRecord(
    mixed $load,
    mixed $reps,
    array $previousLoads,
    array $previousOneRepMaxes
): bool {
    validateWorkoutValues(1, $reps, $load);
    $loadRecord = $previousLoads === [] || (float) $load > max($previousLoads);
    $oneRepMax = estimatedOneRepMax($load, $reps);
    $oneRepMaxRecord = $previousOneRepMaxes === [] || $oneRepMax > max($previousOneRepMaxes);

    return $loadRecord || $oneRepMaxRecord;
}

function validateWorkoutValues(mixed $sets, mixed $reps, mixed $load): void
{
    foreach (['séries' => $sets, 'repetições' => $reps, 'carga' => $load] as $label => $value) {
        if ($value === '' || $value === null || !is_numeric($value)) {
            throw new InvalidArgumentException("Informe um valor válido para {$label}.");
        }
    }

    if ((float) $sets <= 0 || (float) $reps <= 0 || (float) $load < 0) {
        throw new InvalidArgumentException('Séries e repetições devem ser positivas; carga não pode ser negativa.');
    }
}

runTests([
    'calcula volume como séries × repetições × carga' => function (): void {
        assertNear(1200, workoutVolume(4, 10, 30));
    },
    'volume aceita carga zero e valores numéricos grandes' => function (): void {
        assertNear(0, workoutVolume(4, 10, 0));
        assertNear(1_000_000_000, workoutVolume(1000, 1000, 1000));
    },
    'calcula 1RM estimado pela fórmula de Epley' => function (): void {
        assertNear(110, estimatedOneRepMax(100, 3));
    },
    '1RM retorna zero para carga zero' => function (): void {
        assertNear(0, estimatedOneRepMax(0, 10));
    },
    'sugere aumento somente quando todas as séries atingem o topo' => function (): void {
        assertNear(32.5, suggestNextLoad(30, [12, 12, 12], 12));
        assertNear(30, suggestNextLoad(30, [12, 11, 12], 12));
    },
    'progressão rejeita lista vazia e aumento inválido' => function (): void {
        assertThrows(fn() => suggestNextLoad(30, [], 12));
        assertThrows(fn() => suggestNextLoad(30, [12], 12, 0));
    },
    'converte kg para lb e volta sem perder o valor' => function (): void {
        foreach ([0, 20, 72.5, 1_000_000] as $kg) {
            assertNear((float) $kg, poundsToKilograms(kilogramsToPounds($kg)));
        }
    },
    'rejeita carga negativa, repetições não positivas e campos vazios' => function (): void {
        assertThrows(fn() => workoutVolume(3, 10, -1));
        assertThrows(fn() => workoutVolume(3, 0, 10));
        assertThrows(fn() => workoutVolume(3, -1, 10));
        assertThrows(fn() => workoutVolume('', 10, 10));
        assertThrows(fn() => workoutVolume(3, '', 10));
        assertThrows(fn() => workoutVolume(3, 10, ''));
    },
    'identifica recorde de peso ou de 1RM e não sinaliza empate' => function (): void {
        assertSameValue(true, isPersonalRecord(101, 1, [100], [105]));
        assertSameValue(true, isPersonalRecord(90, 8, [100], [110]));
        assertSameValue(false, isPersonalRecord(100, 1, [100], [105]));
        assertSameValue(true, isPersonalRecord(10, 1, [], []));
    },
]);
