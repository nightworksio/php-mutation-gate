<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\DecidingConfig;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\OutOfTime;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\FailFastSavings;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Proof\Keys;
use NightWorksIO\MutationGate\Core\Proof\Measurement;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Doomed;
use NightWorksIO\MutationGate\Core\Verdict\DoomedBy;
use NightWorksIO\MutationGate\Core\Verdict\Failure;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\Undoomed;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Tests\Support\CoverageAsked;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\PeakMemoryFake;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ScriptedRunner;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;
use NightWorksIO\MutationGate\Tests\Support\TickingClock;

afterEach(function (): void {
    Scratch::sweep();
});

/** Every shard of a plan run in a project by this runner, and the results read back. */
$ran = static function (string $project, ScriptedRunner $runner): Results|CannotJudge {
    $plan = Planned::handedIn($project, Planned::twoShards());
    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())
        ->runAll($plan, Workspace::results());

    return Results::read($plan, Workspace::results(), Directory::at($project));
};

/** @return list<string> each unit with where its result came from and each of its mutants */
$described = static fn(Results $results): array => array_map(
    static fn(UnitResult $result): string => sprintf(
        '%s %s: %s; flaky: %s',
        $result->unit()->path()->value(),
        $result->origin()->value,
        implode(', ', array_map(static fn(Mutant $mutant): string => $mutant->nativeId(), [...$result->mutants()])),
        implode(', ', array_map(static fn(MutantId $id): string => $id->value(), [...$result->flaky()])),
    ),
    [...$results->units()],
);

it('reads every shard\'s result, and each unit a shard ran with its own mutants', function () use (
    $ran,
    $described,
): void {
    $results = $ran(Flows::project(), ScriptedRunner::fixture());

    expect($results instanceof Results ? $described($results) : $results)->toBe([
        'src/Money.php run: Plus-11, GreaterThan-16, Minus-21, Decrement-27; flaky: ',
        'src/Held.php run: Plus-11; flaky: ',
    ])
        ->and($results instanceof Results ? array_map(
            static fn(array $read): array => [$read[0]->label(), $read[1]->shard()->number()],
            $results->shards(),
        ) : $results)->toBe([['money', 1], ['held', 2]]);
});

it('gives each unit of a shard the mutants its shard found flaky', function () use ($ran): void {
    $results = $ran(Flows::project(), ScriptedRunner::fixture()->killingAgain());
    $shards = $results instanceof Results ? $results->shards() : [];
    $units = $results instanceof Results ? [...$results->units()] : [];

    expect(count($units))->toBe(2)
        ->and($units[0]->flaky())->toEqual($shards[0][1]->flaky())
        ->and($units[1]->flaky())->toEqual($shards[1][1]->flaky())
        ->and(count($units[0]->flaky()))->toBe(1)
        ->and($units[0]->origin())->toBe(Origin::Run);
});

it('cannot judge a shard that left no result, naming every one that left none', function (): void {
    $project = Flows::project();
    $one = Planned::of(Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(2.0), 'money'));

    expect(Results::read($one, Workspace::results(), Directory::at($project)))
        ->toEqual(CannotJudge::because(<<<'SAID'
            Shard 1 (money) left no result at .mutation-gate/results/1.json, so its mutants cannot be judged.
            A shard that crashed, was cancelled or never started leaves none. Run it again.
            SAID))
        ->and(Results::read(Planned::twoShards(), Workspace::results(), Directory::at($project)))
        ->toEqual(CannotJudge::because(<<<'SAID'
            Shards 1 (money), 2 (held) left no result in .mutation-gate/results, so their mutants cannot be judged.
            A shard that crashed, was cancelled or never started leaves none. Run them again.
            SAID));
});

it('names how the CI said each job ended of a shard that left no result, and what leaves none of the rest', function (): void {
    $project = Flows::project();
    $one = Planned::of(Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(2.0), 'money'));
    Scratch::write($project, '.mutation-gate/results/1.ended.json', '{"how": "shutdown", "job": "https://ci.example/job/1"}');

    expect(Results::read($one, Workspace::results(), Directory::at($project)))
        ->toEqual(CannotJudge::because(<<<'SAID'
            Shard 1 (money) left no result at .mutation-gate/results/1.json, so its mutants cannot be judged.
            Shard 1 (money): its runner received a shutdown signal, which stopped the job: https://ci.example/job/1. Run it again.
            SAID))
        ->and(Results::read(Planned::twoShards(), Workspace::results(), Directory::at($project)))
        ->toEqual(CannotJudge::because(<<<'SAID'
            Shards 1 (money), 2 (held) left no result in .mutation-gate/results, so their mutants cannot be judged.
            Shard 1 (money): its runner received a shutdown signal, which stopped the job: https://ci.example/job/1.
            A shard that crashed, was cancelled or never started leaves none. Run them again.
            SAID));
});

it('names a shard the CI cancelled once another failed apart from the one whose runner it lost', function (): void {
    $project = Flows::project();
    Scratch::write($project, '.mutation-gate/results/1.ended.json', '{"how": "cancelled", "after": 2}');
    Scratch::write($project, '.mutation-gate/results/2.ended.json', '{"how": "lost"}');

    expect(Results::read(Planned::twoShards(), Workspace::results(), Directory::at($project)))
        ->toEqual(CannotJudge::because(<<<'SAID'
            Shards 1 (money), 2 (held) left no result in .mutation-gate/results, so their mutants cannot be judged.
            Shard 1 (money): the CI cancelled it once shard 2 failed, as fail-fast cancels the other shards.
            Shard 2 (held): its runner lost contact with the CI, as a runner starved of memory or CPU does. Run them again.
            SAID));
});

it('reads an ending it cannot read as one the CI never said', function (string $said): void {
    $project = Flows::project();
    $one = Planned::of(Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(2.0), 'money'));
    Scratch::write($project, '.mutation-gate/results/1.ended.json', $said);

    expect(Results::read($one, Workspace::results(), Directory::at($project)))
        ->toEqual(CannotJudge::because(<<<'SAID'
            Shard 1 (money) left no result at .mutation-gate/results/1.json, so its mutants cannot be judged.
            A shard that crashed, was cancelled or never started leaves none. Run it again.
            SAID));
})->with([
    'not JSON' => ['not JSON'],
    'an ending it does not know' => ['{"how": "evicted"}'],
    'no ending' => ['{"job": "https://ci.example/job/1"}'],
    'an address that is no text' => ['{"how": "lost", "job": 7}'],
]);

it('cannot judge a result that cannot be read, and says too which shards left none', function (): void {
    $project = Flows::project();
    Scratch::write($project, '.mutation-gate/results/1.json', 'not a result');

    expect(Results::read(Planned::twoShards(), Workspace::results(), Directory::at($project)))
        ->toEqual(CannotJudge::because(<<<'SAID'
            A shard result cannot be read: the file.format is missing.
            Shard 2 (held) left no result at .mutation-gate/results/2.json, so its mutants cannot be judged.
            A shard that crashed, was cancelled or never started leaves none. Run it again.
            SAID));
});

it('cannot judge a result that followed another plan', function () use ($ran): void {
    $project = Flows::project();
    $ran($project, ScriptedRunner::fixture());
    $other = Planned::of(
        Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(2.0), 'other'),
    );

    expect(Results::read($other, Workspace::results(), Directory::at($project)))
        ->toEqual(CannotJudge::because(<<<'SAID'
            The result of shard 1 followed another plan than this one, so it cannot be merged into it.
            Run every shard of one plan, and the verdict with that plan.
            SAID));
});

it('cannot judge a result left in the place of another shard', function () use ($ran): void {
    $project = Flows::project();
    $ran($project, ScriptedRunner::fixture());
    $results = sprintf('%s/%s', $project, Workspace::results()->value());
    $first = (string) file_get_contents(sprintf('%s/1.json', $results));
    file_put_contents(sprintf('%s/1.json', $results), (string) file_get_contents(sprintf('%s/2.json', $results)));
    file_put_contents(sprintf('%s/2.json', $results), $first);

    expect(Results::read(Planned::handedIn($project, Planned::twoShards()), Workspace::results(), Directory::at($project)))
        ->toEqual(CannotJudge::because(<<<'SAID'
            The result in the place of shard 1 is the result of shard 2, so it cannot stand for it.
            Leave each shard's result under its own number.
            The result in the place of shard 2 is the result of shard 1, so it cannot stand for it.
            Leave each shard's result under its own number.
            SAID));
});

it('cannot judge a result that holds other units than its shard', function () use ($ran): void {
    $project = Flows::project();
    $ran($project, ScriptedRunner::fixture());
    $file = sprintf('%s/%s/1.json', $project, Workspace::results()->value());
    $result = json_decode((string) file_get_contents($file), associative: true);
    file_put_contents($file, JsonText::encode([...(is_array($result) ? $result : []), 'units' => ['src/Other.php' => str_repeat('a', 64)]]));

    expect(Results::read(Planned::handedIn($project, Planned::twoShards()), Workspace::results(), Directory::at($project)))
        ->toEqual(CannotJudge::because(<<<'SAID'
            The result of shard 1 holds other units than the plan gives it, so it cannot stand for it.
            Run every shard of one plan, and the verdict with that plan.
            SAID));
});

it('cannot judge a shard the runner could not judge, saying why for every one', function () use ($ran): void {
    expect($ran(Flows::project(), ScriptedRunner::fixture()->refusing('The suite failed without mutants.')))
        ->toEqual(CannotJudge::because(<<<'SAID'
            Shard 1 (money) could not be judged: The suite failed without mutants.
            Shard 2 (held) could not be judged: The suite failed without mutants.
            SAID));
});

it('cannot judge a shard whose runner skipped mutants it kept no record of, one of them or more', function (int $skipped) use ($ran): void {
    expect($ran(Flows::project(), ScriptedRunner::fixture()->answering(Mutants::none(), $skipped)))
        ->toEqual(CannotJudge::because(sprintf(<<<'SAID'
            Shard 1 (money) skipped %1$d mutants without a record of them, so they cannot be judged.
            The runner leaves no mutant unjudged in a run the gate judges.
            Shard 2 (held) skipped %1$d mutants without a record of them, so they cannot be judged.
            The runner leaves no mutant unjudged in a run the gate judges.
            SAID, $skipped)));
})->with([1, 3]);

it('takes as run every unit of a shard after one it did not run', function (): void {
    $project = Flows::project();
    $units = Units::of(Planned::held(), Planned::money());
    $plan = Planned::handedIn($project, Planned::of(Shard::of(ShardId::of(1), Package::at(Path::root()), $units, Seconds::of(3.0), 'held first')));
    $runner = new CoverageAsked(ScriptedRunner::fixture(), CoverageMap::of());
    new Running(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    expect($results instanceof Results ? array_map(
        static fn(UnitResult $result): string => $result->unit()->path()->value(),
        [...$results->units()],
    ) : $results)->toBe(['src/Money.php'])
        ->and($results instanceof Results ? count($results->misses()) : $results)->toBe(1);
});

it('reads the results from the directory it is handed', function (): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::twoShards());
    new Running(Flows::adapters($project), Flows::settings(), Flows::setup())->runAll($plan, Path::of('elsewhere'));

    expect(Results::read($plan, Path::of('elsewhere'), Directory::at($project)))->toBeInstanceOf(Results::class)
        ->and(Results::read($plan, Workspace::results(), Directory::at($project)))->toBeInstanceOf(CannotJudge::class);
});

it('gathers what every shard warns of, shard by shard', function (): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::twoShards());
    Scratch::write($project, '.mutation-gate/coverage/shard-1/killers.json', 'one');
    Scratch::write($project, '.mutation-gate/coverage/shard-2/killers.json', 'two');
    new Running(Flows::adapters($project), Flows::settings(), Flows::setup())->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    expect($results instanceof Results ? array_map(
        static fn(Warning $warning): string => substr($warning->text(), 0, 7),
        [...$results->warnings()],
    ) : $results)->toBe(['Shard 1', 'Shard 2']);
});

it('keeps each shard\'s result as it was read', function () use ($ran): void {
    $results = $ran(Flows::project(), ScriptedRunner::fixture());

    expect($results instanceof Results ? $results->shards()[0][1] : $results)->toBeInstanceOf(ShardResult::class);
});

it('takes no unit a budget ran out before as run, and names every one', function (): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::twoShards());
    $setup = new Setup(
        Absent::setting(),
        Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
        Digest::sha256Of('installed'),
        new StoppedClock('2026-09-30T12:00:00Z'),
        new PeakMemoryFake(NotGiven::value()),
        DecidingConfig::unread(),
        GateCommand::installed(),
    );
    new Running(Flows::adapters($project), Flows::settings(Budget::of('1s')), $setup)->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    expect($results instanceof Results ? [...$results->units()] : $results)->toBe([])
        ->and($results instanceof Results ? array_map(
            static fn(Unit $unit): string => $unit->path()->value(),
            [...$results->unjudged()],
        ) : $results)->toBe(['src/Money.php', 'src/Held.php']);
});

it('says a run was cut short where its budget ran out before a unit or left a mutant unjudged, and not otherwise', function (
    Settings $settings,
    int $step,
    bool $cut,
    array $left,
): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::oneShard());
    $diff = "@@ @@\n-        return \$a + \$b;\n+        return \$a - \$b;";
    $survivor = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', $diff, 0),
        'Plus-1',
        Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, $diff),
        MutantStatus::Survived,
        Seconds::of(1.0),
    );
    $setup = new Setup(
        Absent::setting(),
        Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
        Digest::sha256Of('installed'),
        new TickingClock('2026-09-30T12:00:00+00:00', $step),
        new PeakMemoryFake(NotGiven::value()),
        DecidingConfig::unread(),
        GateCommand::installed(),
    );
    new Running(Flows::adapters($project, [], ScriptedRunner::fixture()->answering(Mutants::of($survivor), 0)), $settings, $setup)
        ->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    $outOfTime = [];

    foreach ($results instanceof Results ? $results->units() : [] as $unit) {
        foreach ($unit->mutants() as $mutant) {
            $outOfTime = OutOfTime::left($mutant) ? [...$outOfTime, $mutant->nativeId()] : $outOfTime;
        }
    }

    expect($results instanceof Results ? $results->wereCutShort() : $results)->toBe($cut)
        ->and($outOfTime)->toBe($left);
})->with([
    'no budget' => [fn(): Settings => Flows::settings(Timeouts::seconds(5)), 1, false, []],
    'a survivor it had no time to confirm' => [fn(): Settings => Flows::settings(Timeouts::seconds(5), Budget::of('20s')), 1, true, ['Plus-1']],
    'a budget no unit fits' => [fn(): Settings => Flows::settings(Budget::of('1s')), 1, true, []],
]);

/** A shard's result of a plan, written where the verdict reads it: its units, the units it left, and its doom. */
$left = static function (string $project, Plan $plan, int $shard, Units $left, Doomed|Undoomed $doomed): void {
    $planned = $plan->shards()->numbered(ShardId::of($shard));
    $result = ShardResult::of(
        $plan->digest(),
        ShardId::of($shard),
        $planned instanceof Shard ? $plan->keys()->of($planned->units()) : Keys::none(),
        MutationResult::of(Flows::mutantsOf('src/Money.php'), 0),
        Measurement::of(Seconds::of(1.0), 'fake', Instant::at(new DateTimeImmutable('2026-10-07T12:00:00Z'))),
    )->withUnjudged($left)->withDoomed($doomed);
    Scratch::write($project, sprintf('.mutation-gate/results/%d.json', $shard), ShardResultFile::encode($result));
};
$makeDoomed = static fn(): Doomed => Doomed::of(Path::of('src/Money.php'), MutantId::hash(Path::of('src/Money.php'), 'Plus', '1', 0), Path::of('src'), Floor::whole(), DoomedBy::Tree);

/** @return list<string> the paths of these units */
$paths = static fn(Units $units): array => array_map(static fn(Unit $unit): string => $unit->path()->value(), [...$units]);

it('reads a shard that left no result as stopped, where another stopped once the run could not pass, and names that one\'s survivor', function () use (
    $left,
    $makeDoomed,
    $paths,
): void {
    $doomed = $makeDoomed();

    $project = Flows::project();
    $plan = Planned::twoShards();
    $left($project, $plan, 1, Units::none(), $doomed);
    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    expect($results)->toBeInstanceOf(Results::class)
        ->and($results instanceof Results ? $paths($results->stopped()->units()) : $results)->toBe(['src/Held.php'])
        ->and($results instanceof Results ? $paths($results->unjudged()) : $results)->toBe([])
        ->and($results instanceof Results ? $results->stopped()->doomed() : $results)->toEqual($doomed)
        ->and($results instanceof Results ? $results->wereCutShort() : $results)->toBeTrue()
        ->and($results instanceof Results ? array_map(static fn(Failure $failure): string => $failure->text(), [...$results->stopped()->failures()]) : $results)
        ->toBe([$doomed->said(1)]);
});

it('takes the units a doomed shard left as stopped, and those a budget left in another shard as unjudged', function () use (
    $left,
    $makeDoomed,
    $paths,
): void {
    $doomed = $makeDoomed();

    $project = Flows::project();
    $plan = Planned::twoShards();
    $left($project, $plan, 1, Units::of(Planned::money()), $doomed);
    $left($project, $plan, 2, Units::of(Planned::held()), Undoomed::run());
    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    expect($results instanceof Results ? $paths($results->stopped()->units()) : $results)->toBe(['src/Money.php'])
        ->and($results instanceof Results ? $paths($results->unjudged()) : $results)->toBe(['src/Held.php'])
        ->and($results instanceof Results ? [...$results->units()] : $results)->toBe([]);
});

it('names every doomed shard\'s survivor, and the first shard\'s doom as the run\'s', function () use ($left, $makeDoomed): void {
    $doomed = $makeDoomed();

    $project = Flows::project();
    $plan = Planned::twoShards();
    $later = Doomed::of(Path::of('src/Held.php'), MutantId::hash(Path::of('src/Held.php'), 'Plus', '2', 0), Path::of('src'), Floor::whole(), DoomedBy::NewCode);
    $left($project, $plan, 1, Units::none(), $doomed);
    $left($project, $plan, 2, Units::none(), $later);
    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    expect($results instanceof Results ? $results->stopped()->doomed() : $results)->toEqual($doomed)
        ->and($results instanceof Results ? array_map(static fn(Failure $failure): string => $failure->text(), [...$results->stopped()->failures()]) : $results)
        ->toBe([$doomed->said(1), $later->said(2)]);
});

it('stops nothing and dooms nothing where no shard stopped once the run could not pass', function () use ($ran, $paths): void {
    $results = $ran(Flows::project(), ScriptedRunner::fixture());

    expect($results instanceof Results ? $paths($results->stopped()->units()) : $results)->toBe([])
        ->and($results instanceof Results ? $results->stopped()->doomed() : $results)->toEqual(Undoomed::run())
        ->and($results instanceof Results ? count($results->stopped()->failures()) : $results)->toBe(0)
        ->and($results instanceof Results ? $results->wereCutShort() : $results)->toBeFalse();
});

it('stops nothing where each doomed shard judged on, and says what stopping would have saved', function () use ($left, $makeDoomed, $paths): void {
    $project = Flows::project();
    $plan = Planned::twoShards();
    $left($project, $plan, 1, Units::none(), $makeDoomed()->found(Seconds::of(0.25), stopped: false));
    $left($project, $plan, 2, Units::none(), Undoomed::run());
    $results = Results::read($plan, Workspace::results(), Directory::at($project));
    $shards = $results instanceof Results ? array_map(static fn(array $read): ShardResult => $read[1], $results->shards()) : [];

    expect($results instanceof Results ? $paths($results->stopped()->units()) : $results)->toBe([])
        ->and($results instanceof Results ? $results->stopped()->doomed() : $results)->toEqual(Undoomed::run())
        ->and($results instanceof Results ? count($results->stopped()->failures()) : $results)->toBe(0)
        ->and($results instanceof Results ? $results->wereCutShort() : $results)->toBeFalse()
        ->and($results instanceof Results ? $results->savings() : $results)->toEqual(FailFastSavings::of($shards))
        ->and($results instanceof Results ? count($results->savings()->warnings(suggests: true)) : $results)->toBe(1)
        ->and($results instanceof Results ? count($results->savings()->warnings(suggests: false)) : $results)->toBe(0)
        ->and($results instanceof Results ? count($results->warnings()) : $results)->toBe(0);
});

it('still cannot judge a result that cannot be read, beside a doomed shard', function () use ($left, $makeDoomed): void {
    $doomed = $makeDoomed();

    $project = Flows::project();
    $plan = Planned::twoShards();
    $left($project, $plan, 1, Units::none(), $doomed);
    Scratch::write($project, '.mutation-gate/results/2.json', 'not a result');

    expect(Results::read($plan, Workspace::results(), Directory::at($project)))
        ->toEqual(CannotJudge::because('A shard result cannot be read: the file.format is missing.'));
});

/** Two items, money listed for job 1 and held for job 2, of a plan whose shards pull their work. */
$pulledPlan = static fn(): Plan => Planned::of(
    Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(2.0), 'money')
        ->workedBy(ShardId::of(1)),
    Shard::of(ShardId::of(2), Package::at(Path::root()), Units::of(Planned::held()), Seconds::of(1.0), 'held')
        ->workedBy(ShardId::of(2)),
);

/** An item's result as a job wrote it: every mutant killed, or each as the fixture runner judges it. */
$pulledResult = static function (string $project, Plan $plan, int $item, int $job, MutantStatus $status): void {
    $planned = $plan->shards()->numbered(ShardId::of($item));
    $mutants = Mutants::of(...array_map(
        static fn(Mutant $mutant): Mutant => $status === MutantStatus::Survived
            ? $mutant
            : Mutant::of($mutant->id(), $mutant->nativeId(), $mutant->location(), $mutant->mutation(), MutantStatus::Killed, $mutant->duration())
                ->killedBy(TestIds::of(TestId::of('MoneyTest::adds'))),
        [...Flows::mutantsOf('src/Money.php')],
    ));
    $result = ShardResult::of(
        $plan->digest(),
        ShardId::of($item),
        $planned instanceof Shard ? $plan->keys()->of($planned->units()) : Keys::none(),
        MutationResult::of($mutants, 0),
        Measurement::of(Seconds::of(1.0), 'fake', Instant::at(new DateTimeImmutable('2026-10-07T12:00:00Z'))),
    );
    Scratch::write($project, sprintf('.mutation-gate/results/%d.by-%d.json', $item, $job), ShardResultFile::encode($result));
};

it('reads one result for every item of a pulling plan, whichever job left it, or cannot judge an item none left', function (bool $judged, int ...$written) use ($pulledPlan, $pulledResult): void {
    $project = Flows::project();
    $plan = $pulledPlan();

    foreach (array_chunk($written, 2) as [$item, $job]) {
        $pulledResult($project, $plan, $item, $job, MutantStatus::Killed);
    }

    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    expect($results instanceof Results ? array_map(static fn(array $read): int => $read[1]->shard()->number(), $results->shards()) : $judged)
        ->toBe($judged ? [1, 2] : false);
})->with([
    'each by its own job' => [true, 1, 1, 2, 2],
    'each by the other job' => [true, 1, 2, 2, 1],
    'one item by both jobs' => [true, 1, 1, 1, 2, 2, 2],
    'every item by every job' => [true, 1, 1, 1, 2, 2, 1, 2, 2],
    'an item by no job' => [false, 1, 1, 1, 2],
    'nothing' => [false],
]);

it('takes an item\'s own job\'s result first, and counts each mutant another job judged otherwise as flaky', function () use ($pulledPlan, $pulledResult): void {
    $project = Flows::project();
    $plan = $pulledPlan();
    $pulledResult($project, $plan, 1, 1, MutantStatus::Killed);
    $pulledResult($project, $plan, 1, 2, MutantStatus::Survived);
    $pulledResult($project, $plan, 2, 2, MutantStatus::Killed);
    $results = Results::read($plan, Workspace::results(), Directory::at($project));
    $first = $results instanceof Results ? $results->shards()[0][1] : $results;

    $otherwise = array_filter([...Flows::mutantsOf('src/Money.php')], static fn(Mutant $mutant): bool => $mutant->status() !== MutantStatus::Killed);

    expect($otherwise)->not->toBe([])
        ->and($first instanceof ShardResult ? count($first->flaky()) : $first)->toBe(count($otherwise))
        ->and($results instanceof Results ? count($results->shards()[1][1]->flaky()) : $results)->toBe(0);
});
