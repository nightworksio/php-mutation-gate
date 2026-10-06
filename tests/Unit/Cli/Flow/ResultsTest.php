<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Results;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Config\Budget;
use NightWorksIO\MutationGate\Config\Flaky;
use NightWorksIO\MutationGate\Config\Timeouts;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Origin;
use NightWorksIO\MutationGate\Core\Verdict\UnitResult;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
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

it('cannot judge a shard the runner could not judge, saying why for every one', function () use ($ran): void {
    expect($ran(Flows::project(), ScriptedRunner::fixture()->refusing('The suite failed without mutants.')))
        ->toEqual(CannotJudge::because(<<<'SAID'
            Shard 1 (money) could not be judged: The suite failed without mutants.
            Shard 2 (held) could not be judged: The suite failed without mutants.
            SAID));
});

it('cannot judge a shard whose runner skipped mutants it kept no record of', function () use ($ran): void {
    expect($ran(Flows::project(), ScriptedRunner::fixture()->answering(Mutants::none(), 3)))
        ->toEqual(CannotJudge::because(<<<'SAID'
            Shard 1 (money) skipped 3 mutants without a record of them, so they cannot be judged.
            The runner leaves no mutant unjudged in a run the gate judges.
            Shard 2 (held) skipped 3 mutants without a record of them, so they cannot be judged.
            The runner leaves no mutant unjudged in a run the gate judges.
            SAID));
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
): void {
    $project = Flows::project();
    $plan = Planned::handedIn($project, Planned::oneShard());
    $timedOut = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Plus', '@@ @@', 0),
        'Plus-1',
        Location::of(Path::of('src/Money.php'), Line::of(1), Line::of(1)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, '@@ @@'),
        MutantStatus::TimedOut,
        Seconds::of(5.0),
    )->withLimit(Seconds::of(5.0));
    $setup = new Setup(
        Absent::setting(),
        Version::of('nightworksio/mutation-gate', '1.0.0', 'gate'),
        Digest::sha256Of('installed'),
        new TickingClock('2026-09-30T12:00:00+00:00', $step),
        new PeakMemoryFake(NotGiven::value()),
    );
    new Running(Flows::adapters($project, [], ScriptedRunner::fixture()->answering(Mutants::of($timedOut), 0)), $settings, $setup)
        ->runAll($plan, Workspace::results());
    $results = Results::read($plan, Workspace::results(), Directory::at($project));

    expect($results instanceof Results ? $results->wereCutShort() : $results)->toBe($cut);
})->with([
    'no budget' => [Flows::settings(Timeouts::seconds(5), Timeouts::most(5), Timeouts::retries(2), Flaky::notConfirmingSurvivors()), 1, false],
    'a timeout it had no time to run again' => [Flows::settings(Timeouts::seconds(5), Timeouts::most(5), Timeouts::retries(2), Flaky::notConfirmingSurvivors(), Budget::of('6s')), 1, true],
    'a budget no unit fits' => [Flows::settings(Budget::of('1s')), 1, true],
]);
