<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Fakes\CostModelFake;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\RunningCases;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

$ran = static function (int $number, Budget ...$budget): array {
    $project = Flows::project();
    $runner = ScriptedRunner::fixture();
    $shard = static fn(int $at): Shard => Shard::of(
        ShardId::of($at),
        Package::at(Path::root()),
        Units::of(Planned::money()),
        Seconds::of(100.0),
        sprintf('money, part %d of 2', $at),
    )->inPart(TestPart::of($at, 2));

    new Running(
        Flows::adapters($project, [], $runner, new CostModelFake(Seconds::of(100.0))),
        Flows::settings(...$budget),
        Flows::setup(),
    )->run(Planned::handedIn($project, Planned::of($shard(1), $shard(2))), ShardId::of($number), Workspace::results());
    $result = RunningCases::resultIn($project, $number);

    return [
        'requested' => array_map(
            static fn(MutationRequest $request): array => array_map(
                static fn(Path $path): string => $path->value(),
                iterator_to_array($request->files(), preserve_keys: false),
            ),
            $runner->requests(),
        ),
        'unjudged' => RunningCases::unjudged($result),
        'statuses' => RunningCases::statuses($result),
    ];
};

it('weighs a unit at its price in the shard\'s own part of the test files, which a budget fits it by', function () use ($ran): void {
    expect($ran(1, Budget::of('30s'))['requested'])->toBe([])
        ->and($ran(1, Budget::of('30s'))['unjudged'])->toBe(['src/Money.php'])
        ->and($ran(2, Budget::of('30s'))['requested'])->toBe([['src/Money.php']]);
});

it('judges each mutant a budget fits by its price as it judges it with no budget', function () use ($ran): void {
    $fitted = $ran(2, Budget::of('30s'))['statuses'];

    expect($fitted)->not->toBe([])
        ->and($fitted)->toBe($ran(2)['statuses'])
        ->and($ran(1, Budget::of('30s'))['statuses'])->toBe([]);
});
