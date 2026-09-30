<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Telemetry\DataPoint;
use NightWorksIO\MutationGate\Core\Telemetry\Metric;
use NightWorksIO\MutationGate\Core\Telemetry\MetricKind;
use NightWorksIO\MutationGate\Core\Telemetry\Metrics;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdicts;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

$byName = static function (array $metrics): array {
    $named = [];

    foreach ($metrics as $metric) {
        if ($metric instanceof Metric) {
            $named[$metric->name()] = $metric;
        }
    }

    return $named;
};
$points = static fn(Metric $metric): array => array_map(
    static fn(DataPoint $point): array => [$point->value(), $point->attributes()],
    $metric->points(),
);

it('emits each tree\'s and each new-code set\'s score, the mutants by status and the units by how their result came', function () use ($byName, $points): void {
    $metrics = $byName(Metrics::of(Verdicts::failing()));

    expect(array_keys($metrics))->toBe(['mutation_gate.score', 'mutation_gate.mutants', 'mutation_gate.units'])
        ->and($metrics['mutation_gate.score']->kind())->toBe(MetricKind::Gauge)
        ->and($metrics['mutation_gate.score']->unit())->toBe('%')
        ->and($points($metrics['mutation_gate.score']))->toBe([
            [44.44, ['mutation_gate.tree' => 'src']],
            [0.0, ['mutation_gate.tree' => 'new code in .']],
        ])
        ->and($metrics['mutation_gate.mutants']->kind())->toBe(MetricKind::Sum)
        ->and($points($metrics['mutation_gate.mutants']))->toContain([1, ['mutation_gate.status' => 'survived']])
        ->and($points($metrics['mutation_gate.mutants']))->toHaveCount(12)
        ->and($points($metrics['mutation_gate.units']))->toBe([
            [1, ['mutation_gate.status' => 'run']],
            [1, ['mutation_gate.status' => 'proved']],
            [1, ['mutation_gate.status' => 'carried']],
        ]);
});

it('emits each phase\'s duration and the runner minutes for a timed run', function () use ($byName, $points): void {
    $metrics = $byName(Metrics::of(Verdicts::named('accounted')));

    expect($points($metrics['mutation_gate.duration']))->toBe([
        [40.0, ['mutation_gate.phase' => 'plan']],
        [20.0, ['mutation_gate.phase' => 'opening run', 'mutation_gate.shard' => 1]],
        [200.0, ['mutation_gate.phase' => 'mutate', 'mutation_gate.shard' => 1]],
        [25.0, ['mutation_gate.phase' => 'opening run', 'mutation_gate.shard' => 2]],
        [180.0, ['mutation_gate.phase' => 'mutate', 'mutation_gate.shard' => 2]],
        [30.0, ['mutation_gate.phase' => 'verdict']],
    ])
        ->and($metrics['mutation_gate.duration']->unit())->toBe('s')
        ->and($points($metrics['mutation_gate.runner_minutes']))->toBe([[14.0, []]])
        ->and($metrics['mutation_gate.runner_minutes']->unit())->toBe('min');
});

it('leaves out the score of a set with nothing to mutate', function () use ($byName, $points): void {
    $verdict = Verdicts::empty()->withNewCode(NewCodeVerdicts::of(
        NewCodeVerdict::judged(Package::at(Path::root()), Floor::of(100), JudgedMutants::none(), Uncovered::Count),
    ));

    expect($points($byName(Metrics::of($verdict))['mutation_gate.score']))->toBe([]);
});
