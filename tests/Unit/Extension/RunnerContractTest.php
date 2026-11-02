<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Evidences;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Prefix;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Extension\Checks\Records;
use NightWorksIO\MutationGate\Extension\LibraryChange;
use NightWorksIO\MutationGate\Extension\RunnerContract;
use NightWorksIO\MutationGate\Extension\RunnerLibrary;
use NightWorksIO\MutationGate\Port\Runner;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\FilesOfTests;
use NightWorksIO\MutationGate\Tests\Support\MisbehavingRunner;

/** The library as the fake runner spells it. */
function fakeSpelling(): RunnerLibrary
{
    return RunnerLibrary::spelling('Plus', 'GreaterThan', 'Minus', 'Decrement', 'Plus', testEnding: 'Spec.php');
}

/**
 * Every way a runner breaks the contract, as the kit says it.
 *
 * @return list<string>
 */
function runnerFailures(Runner $runner, RunnerLibrary|false $library = false): array
{
    return [...RunnerContract::failures($runner, $library === false ? fakeSpelling() : $library)];
}

/**
 * The fake's run of the library's four mutants of src/Money.php, the one run that asks for four mutators, changed
 * by what this does to it.
 *
 * @param Closure(MutationResult): MutationResult $changed
 */
function fakeMoney(Closure $changed): Closure
{
    return static function (MutationRequest $request) use ($changed): MutationResult {
        $result = RunnerFake::ofTheFixture()->mutate($request);

        return count($request->narrowing()->mutators()) === 4 ? $changed($result) : $result;
    };
}

/**
 * What the fake gives a static analyser, but its survivor of `src/Money.php` printed beside its original as this
 * text.
 *
 * @return Closure(Mutant): (Checkable|CannotJudge)
 */
function printedBeside(Contents $mutant): Closure
{
    return static fn(Mutant $survivor): Checkable|CannotJudge => $survivor->status() === MutantStatus::Survived
        && $survivor->location()->file()->value() === LibraryChange::Large->file()->value()
        ? Checkable::printed(Contents::of('<?php return $amount > 100;'), $mutant)
        : RunnerFake::ofTheFixture()->checkable($survivor);
}

it('finds nothing the fake breaks', function (): void {
    expect(runnerFailures(RunnerFake::ofTheFixture()))->toBe([]);
});

it('says each way a runner answers of itself', function (): void {
    $asked = new ArrayObject();
    $runner = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        identity: static function () use ($asked): Identity {
            $asked->append(1);

            return Identity::of(count($asked) === 1 ? '' : 'other', Versions::none(), Digest::of('php'));
        },
        startUp: static fn(): Seconds => Seconds::none(),
        markers: static fn(): Markers => Markers::of(Marker::of('src/Money.php:11', '@infection-ignore-all', 'an ignore entry')),
        definitions: static fn(): Paths => Paths::of(Path::of('/abs/phpunit.xml'), Path::root(), Path::of('phpunit.xml')),
        rootedAt: static fn(): Runner => RunnerFake::ofTheFixture(),
    );

    expect(runnerFailures($runner))->toBe([
        'It answers another identity the second time it is asked.',
        'It answers an identity with no name.',
        'It answers an identity with no version.',
        'It times a run of no test at no time at all.',
        'It names /abs/phpunit.xml as a file that defines it, not from the project\'s root.',
        'It names . as a file that defines it, not from the project\'s root.',
        'It finds its own ignore markers in src/Money.php, which holds none.',
        'It answers as a runner rooted in src/, which holds no project it can run.',
    ]);
});

it('says what a runner cannot answer of itself', function (): void {
    $runner = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        identity: static fn(): CannotJudge => CannotJudge::because('No PHP.'),
        listing: static fn(): CannotJudge => CannotJudge::because('No tests.'),
        startUp: static fn(): CannotJudge => CannotJudge::because('No start.'),
        markers: static fn(): CannotJudge => CannotJudge::because('No markers.'),
    );

    expect(runnerFailures($runner))->toBe([
        'It answers no identity: No PHP.',
        'It lists no tests: No tests.',
        'It times no run of no test: No start.',
        'It finds no ignore markers: No markers.',
    ]);
});

it('says a runner that lists no test in the group holding src/Held.php', function (TestListing $listing): void {
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), listing: static fn(): TestListing => $listing);

    expect(runnerFailures($runner))->toBe(['It lists no test in the group holds:src/Held.php.']);
})->with([
    'no such group' => fn(): TestListing => TestListing::of(TestIds::of(TestId::of('MoneyTest::adds'))),
    'the group, empty' => fn(): TestListing => TestListing::of(TestIds::none())->grouping(Group::holding('src/Held.php'), TestIds::none()),
]);

it('says a runner whose every key reads a group it does not list, or that also opens each shard', function (): void {
    $unlisted = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        behaviour: static fn(): RunnerBehaviour => RunnerBehaviour::standard()->readingInEveryKey(Group::named('nowhere'))->openingEachShard(),
    );
    $listed = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        behaviour: static fn(): RunnerBehaviour => RunnerBehaviour::standard()->readingInEveryKey(Group::holding('src/Held.php')),
    );

    expect(runnerFailures($unlisted))->toBe([
        'It says every key reads the group nowhere, which it does not list.',
        'It says every key reads groups of its suite, and that each shard opens on its own run.',
    ])->and(runnerFailures($listed))->toBe([]);
});

it('says each group a runner says every key reads and does not list', function (): void {
    $runner = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        behaviour: static fn(): RunnerBehaviour => RunnerBehaviour::standard()
            ->readingInEveryKey(Group::named('nowhere'))
            ->readingInEveryKey(Group::named('elsewhere')),
    );

    expect(runnerFailures($runner))->toBe([
        'It says every key reads the group nowhere, which it does not list.',
        'It says every key reads the group elsewhere, which it does not list.',
    ]);
});

it('finds nothing wrong with a run of no test that takes less than a second', function (): void {
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), startUp: static fn(): Seconds => Seconds::of(0.5));

    expect(runnerFailures($runner))->toBe([]);
});

it('says how a runner reports the four mutants of src/Money.php otherwise', function (): void {
    expect(runnerFailures(RunnerFake::ofTheFixture(), fakeSpelling()->unmeasured()))->toContain(
        'It says how long its killed mutant took, which never ran.',
        'It says how long its survived mutant took, which never ran.',
        'It says how long its timed-out mutant took, which never ran.',
    )->and(runnerFailures(RunnerFake::ofTheFixture(), RunnerLibrary::spelling('A', 'B', 'C', 'D', 'E', 'Spec.php'))[0])
        ->toStartWith('It reports the mutants of src/Money.php as ');
});

it('says a runner that cannot judge the mutants of src/Money.php', function (): void {
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), mutate: static fn(): CannotJudge => CannotJudge::because('Broken.'));

    expect(runnerFailures($runner))->toContain(
        'It cannot judge the mutants of src/Money.php: Broken.',
        'It lets 0 mutants of src/Money.php survive, where its tests let one.',
    );
});

it('says a runner that skips mutants, gives two one id, times and limits them wrongly, and kills too many', function (): void {
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), mutate: fakeMoney(static function (MutationResult $result): MutationResult {
        $mutants = [...$result->mutants()];
        $twin = Mutant::of($mutants[0]->id(), 'twin', $mutants[0]->location(), $mutants[0]->mutation(), MutantStatus::Killed, Seconds::of(0.1));

        return MutationResult::of(
            Mutants::of(
                $mutants[0]->withLimit(Seconds::of(5.0)),
                $twin,
                $mutants[1],
                Mutant::of($mutants[2]->id(), '', $mutants[2]->location(), $mutants[2]->mutation(), MutantStatus::Uncovered, Seconds::of(0.1)),
                Mutant::of($mutants[3]->id(), '', $mutants[3]->location(), $mutants[3]->mutation(), MutantStatus::TimedOut, Seconds::of(0.1)),
            ),
            1,
        )->withSteps($result->steps());
    }));

    expect(runnerFailures($runner))->toContain(
        'It skips 1 mutants of src/Money.php with no record.',
        'It gives two mutants one id.',
        'It gives its killed mutant a time limit, which only a timed-out one is held to.',
        'It says how long its uncovered mutant took, which never ran.',
        'It gives its timed-out mutant no time limit.',
        'It kills 2 mutants of src/Money.php, where its tests kill one.',
    );
});

it('says a runner that times a mutant that ran at no time, or limits its timed-out one to none', function (): void {
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), mutate: fakeMoney(static function (MutationResult $result): MutationResult {
        $mutants = [...$result->mutants()];
        $killed = $mutants[0];

        return MutationResult::of(
            Mutants::of(
                Mutant::of($killed->id(), '', $killed->location(), $killed->mutation(), MutantStatus::Killed, Seconds::none()),
                $mutants[1],
                $mutants[2],
                $mutants[3]->withLimit(Seconds::none()),
            ),
            0,
        )->withSteps($result->steps());
    }));

    expect(runnerFailures($runner))->toBe([
        'It does not say how long its killed mutant took.',
        'It gives its timed-out mutant no time limit.',
    ]);
});

it('finds nothing wrong with a timed-out mutant limited to less than a second', function (): void {
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), mutate: fakeMoney(static function (MutationResult $result): MutationResult {
        $mutants = [...$result->mutants()];

        return MutationResult::of(Mutants::of($mutants[0], $mutants[1], $mutants[2], $mutants[3]->withLimit(Seconds::of(0.5))), 0)
            ->withSteps($result->steps());
    }));

    expect(runnerFailures($runner))->toBe([]);
});

it('finds nothing wrong with a step before the one that runs the mutants, which counts one', function (): void {
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), mutate: fakeMoney(
        static fn(MutationResult $result): MutationResult => $result->withStepsBefore(
            StepTimes::of(StepTime::of(Step::Coverage, Seconds::none(), Seconds::none())),
        ),
    ));

    expect(runnerFailures($runner))->toBe([]);
});

it('says a runner whose steps are out of order, took less than no time, or count other mutants', function (StepTimes $steps): void {
    $runner = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        mutate: fakeMoney(static fn(MutationResult $result): MutationResult => $result->withSteps($steps)),
    );

    expect(runnerFailures($runner))->toBe([
        'It names no one step of running the mutants that counts every mutant it judged.',
        'It names the steps its time went to out of the order they started.',
        'It names a step that took less than no time.',
    ]);
})->with([
    'each wrong' => fn(): StepTimes => StepTimes::of(
        StepTime::of(Step::Mutation, Seconds::of(2.0), Seconds::of(1.0), 3),
        StepTime::of(Step::Coverage, Seconds::of(1.0), Seconds::of(-1.0)),
    ),
]);

it('says a runner whose kill ended, or ended before its first test', function (Evidence $evidence, string $said): void {
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), mutate: fakeMoney(static function (MutationResult $result) use ($evidence): MutationResult {
        $killed = [...$result->mutants()][0];

        return $result->withEvidence(Evidences::none()->with($killed->id(), $evidence));
    }));

    expect(runnerFailures($runner))->toBe([$said]);
})->with([
    'ended' => [fn(): Evidence => Evidence::none()->withEnded(Ended::unprinted(9, signalled: true)), 'It says how a kill that names its killer ended.'],
    'before its first test' => [fn(): Evidence => Evidence::none()->withPrefix(Prefix::at(0)), 'It says a kill ended before the first of its tests.'],
]);

it('says a runner that reports other files than a request asks for', function (): void {
    $money = Paths::of(Path::of('src/Money.php'));
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), mutate: static fn(MutationRequest $request): MutationResult => match (true) {
        ! $request->files()->has(Path::of('src')) => RunnerFake::ofTheFixture()->mutate($request),
        count($request->leftOut()) > 0 => RunnerFake::ofTheFixture()->mutate($request->leavingOut(Paths::none())),
        default => RunnerFake::ofTheFixture()->mutate(MutationRequest::of($money, $request->judgedBy())->narrowedTo($money, $request->narrowing())),
    });

    expect(runnerFailures($runner))->toBe([
        'It reports mutants of src/Money.php for a request of src/, where the library holds src/Held.php, src/Money.php.',
        'It reports mutants of src/Held.php, src/Money.php for a request of src/ less src/Held.php.',
    ]);
});

it('finds nothing wrong with a runner that reports several mutants of one file it is asked for', function (): void {
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), mutate: static fn(MutationRequest $request): MutationResult => RunnerFake::ofTheFixture()->mutate(
        $request->files()->has(Path::of('src'))
            ? MutationRequest::of($request->files(), $request->judgedBy())->leavingOut($request->leftOut())
            : $request,
    ));

    expect(runnerFailures($runner))->toBe([]);
});

it('says a runner that judges a file otherwise beside another, or reports none of it alone', function (): void {
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), mutate: static fn(MutationRequest $request): MutationResult => count($request->files()) === 1 && count($request->narrowing()->mutators()) === 1
        ? MutationResult::skippingNone(Mutants::none())
        : RunnerFake::ofTheFixture()->mutate($request));

    expect(runnerFailures($runner))->toContain(
        'It reports no mutant of src/Money.php or src/Held.php, alone, which each hold one.',
    )->and(array_values(array_filter(
        runnerFailures($runner),
        static fn(string $failure): bool => str_starts_with($failure, 'It reports ') && str_contains($failure, 'together'),
    )))->toHaveCount(1);
});

it('says a runner that reports no mutant of one file alone, though it does of the other', function (): void {
    $held = LibraryChange::Held->file();
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), mutate: static fn(MutationRequest $request): MutationResult => count($request->files()) === 1 && $request->files()->has($held) && $request->judgedBy() instanceof WholeSuite
        ? MutationResult::skippingNone(Mutants::none())
        : RunnerFake::ofTheFixture()->mutate($request));

    expect(runnerFailures($runner))->toHaveCount(2)
        ->and(runnerFailures($runner)[0])->toBe('It reports no mutant of src/Money.php or src/Held.php, alone, which each hold one.');
});

it('says a runner that does not judge a held file by its group alone', function (): void {
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), mutate: static fn(MutationRequest $request): MutationResult|CannotJudge => $request->judgedBy() instanceof Group
        ? CannotJudge::because('No groups.')
        : RunnerFake::ofTheFixture()->mutate($request));

    expect(runnerFailures($runner))->toContain(sprintf(
        'It reports nothing it can judge (No groups.) for src/Held.php judged by its group alone, where its group lets it survive: %s.',
        implode('; ', Records::expected(fakeSpelling(), LibraryChange::Held)),
    ));
});

it('says a runner that cannot run its survivor again, nor reproduce it, nor give it to an analyser', function (): void {
    $runner = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        checkable: static fn(): CannotJudge => CannotJudge::because('No text.'),
        retry: static fn(): CannotJudge => CannotJudge::because('No retry.'),
        reproduce: static fn(): CannotJudge => CannotJudge::because('No reproduction.'),
    );

    expect(runnerFailures($runner))->toBe([
        'It runs its survivor again as nothing it can judge (No retry.).',
        'It runs its survivor again by a group that reaches none of its lines as .',
        'It reproduces its survivor as nothing it can judge (No reproduction.).',
        'It reproduces its survivor printing nothing of the run.',
        'It reproduces its survivor by a group that holds another file as nothing it can judge (No reproduction.).',
        'It reproduces a mutant its run no longer makes.',
        'It gives its survivor to no static analyser: No text.',
    ]);
});

it('says a runner that reproduces every mutant as unmade, printing nothing, and gives an analyser what it should not', function (): void {
    $runner = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        checkable: static fn(): Checkable => Checkable::printed(Contents::of('<?php // another file'), Contents::of('<?php return $amount > 100;')),
        reproduce: static fn(Reproducible $mutant): Reproduction => Reproduction::among($mutant->id(), Mutants::none(), Reason::that('Gone.'), ''),
    );

    expect(runnerFailures($runner))->toBe([
        'It reproduces its survivor as unmade (Gone.).',
        'It reproduces its survivor printing nothing of the run.',
        'It reproduces its survivor by a group that holds another file as unmade (Gone.).',
        'It gives its survivor to a static analyser without its change made: <?php return $amount > 100;',
        'It gives its survivor to a static analyser with more changed than its change.',
        'It gives a static analyser a mutant of src/Gone.php, which is not there.',
    ]);
});

it('finds nothing wrong with a runner that gives an analyser its survivor printed beside its original', function (): void {
    $runner = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        checkable: printedBeside(Contents::of('<?php return $amount >= 100;')),
    );

    expect(runnerFailures($runner))->toBe([]);
});

it('says a runner that gives an analyser its survivor with the change made beside the line it replaces', function (): void {
    $runner = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        checkable: printedBeside(Contents::of('<?php return $amount >= 100; return $amount > 100;')),
    );

    expect(runnerFailures($runner))->toBe([
        'It gives its survivor to a static analyser without its change made: <?php return $amount >= 100; return $amount > 100;',
        'It gives its survivor to a static analyser with more changed than its change.',
    ]);
});

it('says a runner that measures no coverage of the whole suite', function (): void {
    $runner = new MisbehavingRunner(RunnerFake::ofTheFixture(), coverage: static fn(): CannotJudge => CannotJudge::because('No driver.'));

    expect(runnerFailures($runner))->toBe(['It measures no coverage of the whole suite: No driver.']);
});

it('says a runner that names other judges, reads back another map, or measures another across two processes', function (): void {
    $runner = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        coverage: static fn(CoverageRun|CoverageRead|CoverageRan $request): CoverageMap|CannotJudge => match (true) {
            $request instanceof CoverageRan => CoverageMap::empty(),
            $request instanceof CoverageRun && $request->processes()->count() === 2 && $request->tests() instanceof WholeSuite => CannotJudge::because('One process.'),
            default => RunnerFake::ofTheFixture()->coverage($request),
        },
        judges: static fn(): Paths => Paths::of(Path::of('tests/OtherSpec.php')),
    );

    expect(runnerFailures($runner))->toBe([
        'It names tests/OtherSpec.php as the test files that judge src/Money.php, where tests/DrainSpec.php, tests/MoneySpec.php do.',
        'It names tests/OtherSpec.php as test files that judge src/Nowhere.php, which no test covers.',
        'It reads the reports its own coverage run left as another map than that run measured.',
        'It measures another map across two processes than in one.',
    ]);
});

it('says a runner that measures other lines across two processes, though it runs the same tests', function (): void {
    $fewer = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('MoneyTest::adds'))
        ->covered(Path::of('src/Held.php'), Line::of(11), TestId::of('HeldTest::doubles'));
    $runner = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        coverage: static fn(CoverageRun|CoverageRead|CoverageRan $request): CoverageMap => $request instanceof CoverageRun && $request->processes()->count() === 2 && $request->tests() instanceof WholeSuite
            ? $fewer
            : RunnerFake::ofTheFixture()->coverage($request),
    );

    expect(runnerFailures($runner))->toBe(['It measures another map across two processes than in one.']);
});

it('measures two test files again at once, of the three its map holds tests of', function (): void {
    $names = TestNames::none()
        ->with(TestId::of('MoneyTest::adds'), TestName::in(Path::of('tests/MoneyTest.php'), 'it adds'))
        ->with(TestId::of('HeldTest::doubles'), TestName::in(Path::of('tests/HeldTest.php'), 'it doubles'))
        ->with(TestId::of('DrainTest::drains'), TestName::in(Path::of('tests/DrainTest.php'), 'it drains'));
    $files = new FilesOfTests($names);
    $whole = RunnerFake::ofTheFixture()->coverage(CoverageRan::in(Path::of('.mutation-gate/coverage')))
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('DrainTest::drains'));
    $runner = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        coverage: static fn(CoverageRun|CoverageRead|CoverageRan $request): CoverageMap|CannotJudge => match (true) {
            ! $request instanceof CoverageRun || ! $request->tests() instanceof TestPaths => $whole,
            count($request->tests()->files()) > 2 => CannotJudge::because('Three files at once.'),
            default => $files->measured($request->tests()->files(), $whole),
        },
        testsIn: static fn(Paths $paths, CoverageMap $map): TestIds => $files->holding($paths, $map),
        names: static fn(): TestNames => $names,
    );

    expect(runnerFailures($runner))->toBe([]);
});

it('says a runner that cannot place its tests in test files, nor measure them again', function (): void {
    $measuredAgain = static fn(CoverageMap $again): Closure => static fn(CoverageRun|CoverageRead|CoverageRan $request): CoverageMap => $request instanceof CoverageRun && $request->tests() instanceof TestPaths
        ? $again
        : RunnerFake::ofTheFixture()->coverage($request);
    $unnamed = new MisbehavingRunner(RunnerFake::ofTheFixture(), names: static fn(): TestNames => TestNames::none());
    $unplaced = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        coverage: $measuredAgain(CoverageMap::empty()),
        testsIn: static fn(): TestIds => TestIds::none(),
    );
    $elsewhere = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        coverage: $measuredAgain(CoverageMap::empty()->covered(Path::of('src/Other.php'), Line::of(1), TestId::of('Other::test'))),
    );

    expect(runnerFailures($unnamed))->toBe(['It names the tests of its map in fewer than two test files.'])
        ->and(runnerFailures($unplaced))->toBe([
            'It measures no test again of tests/MoneyTest.php, whose tests its map holds.',
            'It places some tests of its map in none of the test files that hold them.',
        ])->and(runnerFailures($elsewhere))->toBe([
            'It measures a test file again into a map that, merged into the whole, is not the whole.',
            'It measures two test files again at once into other tests than it places in them.',
        ]);
});

it('says a runner that cannot name what judges a file, place its tests, or answer its identity again', function (): void {
    $asked = new ArrayObject();
    $runner = new MisbehavingRunner(
        RunnerFake::ofTheFixture(),
        identity: static function () use ($asked): Identity|CannotJudge {
            $asked->append(1);

            return count($asked) === 1 ? RunnerFake::ofTheFixture()->identity(Withheld::standard()) : CannotJudge::because('No PHP now.');
        },
        testsIn: static fn(): CannotJudge => CannotJudge::because('No names.'),
        judges: static fn(): CannotJudge => CannotJudge::because('No map.'),
    );

    expect(runnerFailures($runner))->toBe([
        'It answers another identity the second time it is asked.',
        'It names nothing it can judge (No map.) as the test files that judge src/Money.php, where tests/DrainSpec.php, tests/MoneySpec.php do.',
        'It names nothing it can judge (No map.) as test files that judge src/Nowhere.php, which no test covers.',
        'It measures no test again of tests/MoneyTest.php, whose tests its map holds.',
        'It places some tests of its map in none of the test files that hold them.',
        'It measures a test file again into a map that, merged into the whole, is not the whole.',
        'It measures two test files again at once into other tests than it places in them.',
    ]);
});
