<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\MutantTime;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;
use Pest\Mutate\Mutators\Removal\RemoveArrayItem;

afterEach(function (): void {
    putenv(GateVariable::MutantFloor->value);
    putenv(GateVariable::MutantCap->value);
    putenv(GateVariable::MutantStartUp->value);
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

it('holds a mutant\'s run to the standard limit of its slowest covering test\'s own time once its tests begin, and none where one is not timed or no bounds are named', function (): void {
    mutantTimeBounds('2', '300');
    MutantTime::remember(['testResults' => mutantTimeTests(['T::a' => 0.25, 'T::b' => 1.5, 'T::never' => 0.0])]);

    expect(MutantTime::silence(['T::a', 'T::b'], 'P\\Plus'))->toEqual(Seconds::of(9.5))
        ->and(MutantTime::silence(['T::a'], 'P\\Plus'))->toEqual(Seconds::of(5.75))
        ->and(MutantTime::silence(['T::a', 'T::never'], 'P\\Plus'))->toEqual(NotGiven::value())
        ->and(MutantTime::silence([], 'P\\Plus'))->toEqual(NotGiven::value());

    putenv(GateVariable::MutantFloor->value);

    expect(MutantTime::silence(['T::a'], 'P\\Plus'))->toEqual(NotGiven::value());
});

it('keeps the silence limit of a mutant of a mutator timeouts.tighter lists above its lower floor, by the mutator\'s short name', function (): void {
    mutantTimeBounds('10', '300');
    putenv(sprintf('%s=7', GateVariable::TighterFloor->value));
    putenv(sprintf('%s=RemoveArrayItem,Ternary', GateVariable::TighterMutators->value));
    MutantTime::remember(['testResults' => mutantTimeTests(['T::a' => 0.25])]);

    try {
        expect(MutantTime::silence(['T::a'], RemoveArrayItem::class))->toEqual(Seconds::of(7.0))
            ->and(MutantTime::silence(['T::a'], PlusToMinus::class))->toEqual(Seconds::of(10.0));
    } finally {
        putenv(GateVariable::TighterFloor->value);
        putenv(GateVariable::TighterMutators->value);
    }
});

it('lays a mutant\'s limit, and its silence limit, on three times the start-up the gate\'s run measured, in place of five seconds', function (): void {
    mutantTimeResults();
    mutantTimeBounds('2', '300');
    putenv(sprintf('%s=4', GateVariable::MutantStartUp->value));
    MutantTime::remember(['testResults' => mutantTimeTests(['T::a' => 0.25, 'T::b' => 0.5])]);

    expect(MutantTime::of(['T::a', 'T::b'], '/tmp/mutations/abc', 99))->toBe(14.25)
        ->and(MutantTime::silence(['T::a', 'T::b'], PlusToMinus::class))->toEqual(Seconds::of(13.5));
});

it('records a mutant\'s limit after what the results file holds already, and keeps bounds of under a second', function (): void {
    $results = mutantTimeResults();
    file_put_contents($results, "{\"event\":\"made\"}\n");
    mutantTimeBounds('0.25', '0.5');
    MutantTime::remember(['testResults' => mutantTimeTests(['T::a' => 0.25])]);

    expect(MutantTime::of(['T::a'], '/tmp/mutations/abc', 99))->toBe(0.5)
        ->and(file_get_contents($results))->toBe(sprintf("{\"event\":\"made\"}\n%s", RecordLine::limited('/tmp/mutations/abc', 0.5)));
});
