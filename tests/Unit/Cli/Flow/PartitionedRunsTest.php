<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Adapters;
use NightWorksIO\MutationGate\Cli\Flow\Mutated;
use NightWorksIO\MutationGate\Cli\Flow\PartitionedRuns;
use NightWorksIO\MutationGate\Cli\Flow\PathGroups;
use NightWorksIO\MutationGate\Cli\Flow\Ruling;
use NightWorksIO\MutationGate\Core\Analysis\SurvivorChecks;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Hold\HeldChecks;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\DoomedBy;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A shard of these files' units, grouped by these `paths` entries. */
$groups = static fn(array $paths, string ...$files): PathGroups => PathGroups::of(
    Shard::of(
        ShardId::of(1),
        Package::at(Path::root()),
        Units::of(...array_map(static fn(string $file): Unit => Unit::file(Path::of($file)), $files)),
        Seconds::of(1.0),
        'all',
    ),
    Configs::valid(['paths' => $paths])->setup()->paths(),
);

/** What a group's run came to: one flaky mutant named after its first unit, and doomed where asked. */
$mutated = static fn(Shard $shard, bool $dooms = false): Mutated => new Mutated(
    MutationResult::of(Mutants::none(), 1),
    MutantIds::of(MutantId::firstOf([...$shard->units()][0]->path(), 'Plus', '-a +b')),
    HeldChecks::none(),
    Units::none(),
    SurvivorChecks::none(),
    $dooms
        ? Doomed::of([...$shard->units()][0]->path(), MutantId::firstOf(Path::of('src/A.php'), 'Plus', '-a +b'), Path::of('src'), Floor::whole(), DoomedBy::Tree)
        : Undoomed::run(),
);

/** The runs of a partitioned shard, its other groups' adapters built by this. */
$runs = static fn(Closure $wiring): PartitionedRuns => new PartitionedRuns(
    Flows::adapters(Flows::project()),
    Flows::settings(),
    Ruling::by($wiring),
    new DateTimeImmutable(Configs::NOW),
);

$paths = [['glob' => 'src/Legacy/**', 'timeouts' => ['seconds' => 60]], ['glob' => 'src/Gen/**', 'timeouts' => ['seconds' => 90]]];

it('runs each group with its own settings and joins what they came to', function () use ($groups, $mutated, $runs, $paths): void {
    $seen = [];
    $outcome = $runs(static fn(Settings $settings, Directory $project): Adapters => Flows::adapters($project->root()->value()))->mutated(
        $groups($paths, 'src/Money.php', 'src/Legacy/A.php', 'src/Gen/B.php'),
        static function (Adapters $adapters, Settings $settings, Shard $shard) use (&$seen, $mutated): Mutated {
            $seen[] = [$settings->triage()->limit()->seconds(), [...$shard->units()][0]->path()->value()];

            return $mutated($shard);
        },
    );

    expect($seen)->toBe([[10.0, 'src/Money.php'], [60.0, 'src/Legacy/A.php'], [90.0, 'src/Gen/B.php']])
        ->and($outcome instanceof Mutated ? [count($outcome->flaky), $outcome->result->skipped()] : $outcome)->toBe([3, 3]);
});

it('leaves the groups after a doom unjudged, and stops at the first that cannot judge', function () use ($groups, $mutated, $runs, $paths): void {
    $wiring = static fn(Settings $settings, Directory $project): Adapters => Flows::adapters($project->root()->value());
    $doomed = $runs($wiring)->mutated(
        $groups($paths, 'src/Money.php', 'src/Legacy/A.php', 'src/Gen/B.php'),
        static fn(Adapters $adapters, Settings $settings, Shard $shard): Mutated => $mutated(
            $shard,
            [...$shard->units()][0]->path()->value() === 'src/Legacy/A.php',
        ),
    );
    $refused = $runs($wiring)->mutated(
        $groups($paths, 'src/Money.php', 'src/Legacy/A.php'),
        static fn(Adapters $adapters, Settings $settings, Shard $shard): Mutated|CannotJudge => [...$shard->units()][0]->path()->value() === 'src/Money.php'
            ? $mutated($shard)
            : CannotJudge::because('The runner broke.'),
    );

    expect($doomed instanceof Mutated ? array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$doomed->unjudged]) : $doomed)
        ->toBe(['src/Gen/B.php'])
        ->and($doomed instanceof Mutated ? $doomed->doomed : $doomed)->toBeInstanceOf(Doomed::class)
        ->and($refused)->toEqual(CannotJudge::because('The runner broke.'));
});

it('cannot judge a group whose adapters cannot be built, saying why', function () use ($groups, $mutated, $runs, $paths): void {
    $run = static fn(Adapters $adapters, Settings $settings, Shard $shard): Mutated => $mutated($shard);
    $invalid = $runs(static fn(): Invalid => Invalid::because(Problem::at('paths[0].mutators.except', 'no such mutator')))
        ->mutated($groups($paths, 'src/Legacy/A.php'), $run);
    $unjudged = $runs(static fn(): CannotJudge => CannotJudge::because('No runner.'))
        ->mutated($groups($paths, 'src/Legacy/A.php'), $run);

    expect($invalid)->toEqual(CannotJudge::because('A group of units paths entries match cannot be run: paths[0].mutators.except: no such mutator'))
        ->and($unjudged)->toEqual(CannotJudge::because('No runner.'))
        ->and($runs(static fn(): CannotJudge => CannotJudge::because('unused'))->mutated($groups($paths), $run))
        ->toEqual(CannotJudge::because('The shard held no group of units to run.'));
});

it('cannot judge a group whose entries leave its settings invalid', function () use ($groups, $mutated): void {
    $settings = Configs::settings(['runner' => 'pest', 'timeouts' => ['seconds' => 10, 'most' => 100]]);
    $runs = new PartitionedRuns(
        Flows::adapters(Flows::project()),
        $settings,
        Ruling::by(static fn(): CannotJudge => CannotJudge::because('unused')),
        new DateTimeImmutable(Configs::NOW),
    );

    expect($runs->mutated(
        $groups([['glob' => 'src/**', 'timeouts' => ['seconds' => 200]]], 'src/A.php'),
        static fn(Adapters $adapters, Settings $settings, Shard $shard): Mutated => $mutated($shard),
    ))->toEqual(CannotJudge::because('A group of units paths entries match cannot be run: timeouts.most: expected at least timeouts.seconds, 200, got 100'));
});
