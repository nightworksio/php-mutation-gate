<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\RunnerMarkers;
use NightWorksIO\MutationGate\Config\Ignores;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\Shards;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Growth;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;

afterEach(function (): void {
    Scratch::sweep();
});

it('looks for the runner\'s markers in the files of even one unit, and asks nothing of none', function (): void {
    $refusing = static fn(Markers|CannotJudge $markers, Units $units): Units|CannotJudge => new RunnerMarkers(
        Flows::adapters(Scratch::directory(), [], ScriptedRunner::fixture()->marking($markers)),
        Flows::settings(),
    )->refusing($units);
    $marked = Markers::of(Marker::of('infection.json5 mutators.global-ignore', 'ignore', '{"path": "src/Money.php"}'));

    expect([
        $refusing($marked, Units::of(Unit::file(Path::of('src/Money.php')))),
        $refusing(CannotJudge::because('infection.json5 cannot be read.'), Units::none()),
    ])->toEqual([
        CannotJudge::because(<<<'SAID'
            The runner's own ignore markers hide mutants with no reason and no end,
            so the run cannot go ahead:
              infection.json5 mutators.global-ignore, ignore
                replaced by {"path": "src/Money.php"}
            Replace each with its entry in ignores.entries,
            or set ignores.native: allow while the project moves them there.
            SAID),
        Units::none(),
    ]);
});

it('counts the markers in a plan\'s files in time linear in its shards\' units, however many shards share a unit', function (): void {
    $markers = new RunnerMarkers(
        Flows::adapters(Scratch::directory(), [], ScriptedRunner::fixture()->marking(Markers::none())),
        Flows::settings(Ignores::allowingNativeMarkers()),
    );
    $counting = static function (int $size) use ($markers): Closure {
        $units = Units::of(...array_map(
            static fn(int $file): Unit => Unit::file(Path::of(sprintf('src/F%d.php', $file))),
            range(1, 50),
        ));
        $plan = Plan::of(Revision::ref('5eeca8f'), Digest::sha256Of('base'), Keys::none(), Shards::of(...array_map(
            static fn(int $shard): Shard => Shard::of(ShardId::of($shard), Package::at(Path::root()), $units, Seconds::of(1.0), 'src'),
            range(1, $size),
        )));

        return static fn(): int => count($markers->allowed($plan));
    };

    expect(Growth::of(20, $counting))->toBeLessThan(Growth::LINEAR);
});
