<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\MutantTime;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    putenv(GateVariable::MutantFloor->value);
    putenv(GateVariable::MutantCap->value);
    putenv(GateVariable::Results->value);
    MutantTime::remember([]);
    Scratch::sweep();
});

/** The results file the gate names, in a fresh directory. */
function mutantTimeResults(): string
{
    $results = sprintf('%s/results.jsonl', Scratch::directory());
    putenv(sprintf('%s=%s', GateVariable::Results->value, $results));

    return $results;
}

/** The bounds the gate names: a floor and a most, as it writes them. */
function mutantTimeBounds(string $floor, string $most): void
{
    putenv(sprintf('%s=%s', GateVariable::MutantFloor->value, $floor));
    putenv(sprintf('%s=%s', GateVariable::MutantCap->value, $most));
}

/**
 * Each test's result, as php-code-coverage keeps one, taking these seconds.
 *
 * @param  array<string, float>                                           $seconds by test
 * @return array<string, array{size: string, status: string, time: float}>
 */
function mutantTimeTests(array $seconds): array
{
    return array_map(static fn(float $time): array => ['size' => 'unknown', 'status' => 'success', 'time' => $time], $seconds);
}

it('allows a mutant five seconds and three times its covering tests\' own time, each test once, and records it by its mutated copy', function (): void {
    $results = mutantTimeResults();
    mutantTimeBounds('2', '300');
    MutantTime::remember(['testResults' => mutantTimeTests(['T::a' => 0.25, 'T::b' => 0.5, 'T::slow' => 83.16])]);

    expect(MutantTime::of(['T::a', 'T::b', 'T::a'], '/tmp/mutations/abc', 99))->toBe(7.25)
        ->and(file_get_contents($results))->toBe(RecordLine::limited('/tmp/mutations/abc', 7.25));
});

it('allows a mutant no less than the floor, nor more than the most, and the floor where a covering test is not timed', function (): void {
    mutantTimeResults();
    mutantTimeBounds('10', '30');
    MutantTime::remember(['testResults' => mutantTimeTests(['T::a' => 0.25, 'T::slow' => 9.0, 'T::never' => 0.0])]);

    expect(MutantTime::of(['T::a'], '/tmp/mutations/fast', 99))->toBe(10.0)
        ->and(MutantTime::of(['T::slow'], '/tmp/mutations/slow', 99))->toBe(30.0)
        ->and(MutantTime::of(['T::a', 'T::unknown'], '/tmp/mutations/unknown', 99))->toBe(10.0)
        ->and(MutantTime::of(['T::never'], '/tmp/mutations/never', 99))->toBe(10.0)
        ->and(MutantTime::of([], '/tmp/mutations/none', 99))->toBe(10.0);
});

it('keeps the limit Pest gave a mutant where the gate names no bounds, or the limit cannot be recorded', function (string $floor, string $most, string $results, string $mutated): void {
    mutantTimeBounds($floor, $most);
    putenv(sprintf('%s=%s', GateVariable::Results->value, $results === '' ? '' : sprintf('%s/%s', Scratch::directory(), $results)));
    MutantTime::remember(['testResults' => mutantTimeTests(['T::a' => 0.25])]);
    // A write that fails warns, as PHP does, and records nothing.
    set_error_handler(static fn(): bool => true);

    try {
        $limit = MutantTime::of(['T::a'], $mutated, 99);
    } finally {
        restore_error_handler();
    }

    expect($limit)->toBe(99.0);
})->with([
    'no most' => ['10', '', 'results.jsonl', '/tmp/mutations/abc'],
    'a most that is not a number' => ['10', '300s', 'results.jsonl', '/tmp/mutations/abc'],
    'a most of no seconds' => ['10', '0', 'results.jsonl', '/tmp/mutations/abc'],
    'no floor' => ['', '300', 'results.jsonl', '/tmp/mutations/abc'],
    'a floor that is not a number' => ['10s', '300', 'results.jsonl', '/tmp/mutations/abc'],
    'a floor of no seconds' => ['0', '300', 'results.jsonl', '/tmp/mutations/abc'],
    'no results file' => ['10', '300', '', '/tmp/mutations/abc'],
    'a results file that cannot be written' => ['10', '300', 'missing/results.jsonl', '/tmp/mutations/abc'],
    'no mutated copy' => ['10', '300', 'results.jsonl', ''],
]);

it('forgets the times it kept once it reads another map', function (): void {
    mutantTimeResults();
    mutantTimeBounds('2', '300');
    MutantTime::remember(['testResults' => mutantTimeTests(['T::a' => 0.25])]);
    MutantTime::remember(['testResults' => mutantTimeTests(['T::b' => 0.25])]);

    expect(MutantTime::of(['T::a'], '/tmp/mutations/abc', 99))->toBe(2.0);
});
