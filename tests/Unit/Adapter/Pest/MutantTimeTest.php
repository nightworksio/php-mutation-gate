<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\MutantTime;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
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

it('allows a mutant five seconds and five times its covering tests\' own time, each test once, and records it by its mutated copy', function (): void {
    $results = mutantTimeResults();
    putenv(sprintf('%s=10', GateVariable::MutantCap->value));
    MutantTime::remember(['testResults' => mutantTimeTests(['T::a' => 0.25, 'T::b' => 0.5, 'T::slow' => 83.16])]);

    expect(MutantTime::of(['T::a', 'T::b', 'T::a'], '/tmp/mutations/abc', 99))->toBe(8.75)
        ->and(file_get_contents($results))->toBe(RecordLine::limited('/tmp/mutations/abc', 8.75));
});

it('allows a mutant no more than the cap, nor where a covering test is not timed', function (): void {
    mutantTimeResults();
    putenv(sprintf('%s=10', GateVariable::MutantCap->value));
    MutantTime::remember(['testResults' => mutantTimeTests(['T::a' => 0.25, 'T::slow' => 2.0, 'T::never' => 0.0])]);

    expect(MutantTime::of(['T::slow'], '/tmp/mutations/slow', 99))->toBe(10.0)
        ->and(MutantTime::of(['T::a', 'T::unknown'], '/tmp/mutations/unknown', 99))->toBe(10.0)
        ->and(MutantTime::of(['T::never'], '/tmp/mutations/never', 99))->toBe(10.0)
        ->and(MutantTime::of([], '/tmp/mutations/none', 99))->toBe(10.0);
});

it('keeps the limit Pest gave a mutant where the gate names no cap, or the limit cannot be recorded', function (string $cap, string $results, string $mutated): void {
    putenv(sprintf('%s=%s', GateVariable::MutantCap->value, $cap));
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
    'no cap' => ['', 'results.jsonl', '/tmp/mutations/abc'],
    'a cap that is not a number' => ['10s', 'results.jsonl', '/tmp/mutations/abc'],
    'a cap of no seconds' => ['0', 'results.jsonl', '/tmp/mutations/abc'],
    'no results file' => ['10', '', '/tmp/mutations/abc'],
    'a results file that cannot be written' => ['10', 'missing/results.jsonl', '/tmp/mutations/abc'],
    'no mutated copy' => ['10', 'results.jsonl', ''],
]);

it('forgets the times it kept once it reads another map', function (): void {
    mutantTimeResults();
    putenv(sprintf('%s=10', GateVariable::MutantCap->value));
    MutantTime::remember(['testResults' => mutantTimeTests(['T::a' => 0.25])]);
    MutantTime::remember(['testResults' => mutantTimeTests(['T::b' => 0.25])]);

    expect(MutantTime::of(['T::a'], '/tmp/mutations/abc', 99))->toBe(10.0);
});
