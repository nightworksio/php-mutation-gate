<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\RunTime;
use NightWorksIO\MutationGate\Core\Cost\RunTimings;
use NightWorksIO\MutationGate\Core\Telemetry\Span;
use NightWorksIO\MutationGate\Core\Telemetry\Trace;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$timings = static function (): RunTimings {
    $timings = Verdicts::account()->timings();

    return $timings instanceof RunTimings ? $timings : RunTimings::of('none', RunTime::measured(Seconds::of(0.0), Seconds::of(0.0)));
};

it('spans the plan, each shard with its opening run and mutation under it, and the verdict', function () use ($timings): void {
    $spans = Trace::spans($timings(), ['cicd.pipeline.run.id' => 'github:12345/1']);

    expect(array_map(static fn(Span $span): string => $span->name(), $spans))
        ->toBe(['plan', 'shard 1', 'opening run', 'mutate', 'shard 2', 'opening run', 'mutate', 'verdict'])
        ->and($spans[2]->parent())->toBe($spans[1]->id())
        ->and($spans[3]->parent())->toBe($spans[1]->id())
        ->and($spans[6]->parent())->toBe($spans[4]->id())
        ->and($spans[0]->parent())->toBe('')
        ->and($spans[7]->parent())->toBe('')
        ->and($spans[0]->attributes())->toBe(['cicd.pipeline.run.id' => 'github:12345/1'])
        ->and($spans[5]->attributes())->toBe(['cicd.pipeline.run.id' => 'github:12345/1', 'mutation_gate.shard' => 2]);
});

it('spans a shard from its opening run\'s start for as long as both its steps took', function () use ($timings): void {
    $shard = Trace::spans($timings(), [])[1];

    expect($shard->phase()->start()->value())->toBe('2026-09-30T11:51:00Z')
        ->and($shard->phase()->duration())->toEqual(Seconds::of(220.0));
});

it('draws each span id from the trace and the span\'s path, so every job names it alike', function () use ($timings): void {
    $trace = $timings()->traceId();
    $spans = Trace::spans($timings(), []);

    expect($spans[0]->id())->toBe(mb_substr(hash('sha256', sprintf('%s/plan', $trace)), 0, 16))
        ->and($spans[2]->id())->toBe(Trace::spanId($trace, 'shard 1/opening run'))
        ->and($spans[0]->id())->toMatch('/^[0-9a-f]{16}$/')
        ->and(array_unique(array_map(static fn(Span $span): string => $span->id(), $spans)))->toHaveCount(8);
});

it('spans only what was measured', function (): void {
    expect(Trace::spans(RunTimings::of('run', RunTime::measured(Seconds::of(1.0), Seconds::of(1.0))), []))->toBe([]);
});
