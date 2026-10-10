<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Clock;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\Diff;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Interpretation;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Pest\PestStatus;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Ended;
use NightWorksIO\MutationGate\Core\Mutant\Evidence;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Runner\TighterVariables;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
use NightWorksIO\MutationGate\Tests\Support\PestCases;
use NightWorksIO\MutationGate\Tests\Support\PestRun;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

it('runs the mutants again in one run of their files with their mutators, naming each to a patched plugin, matched back by id', function (): void {
    $at = PestCases::project();
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
    $survivor = PestCases::mutant();
    $place = Location::of(Path::of('src/Money.php'), Line::of(12), Line::of(12));
    $change = Mutation::of(PestCases::RUN_PLUS, MutatorFamily::Arithmetic, '-gone');
    $id = MutantId::hash(Path::of('src/Money.php'), PestCases::RUN_PLUS, '-gone', 0);
    $gone = Mutant::of($id, 'n9', $place, $change, MutantStatus::Survived, Unmeasured::duration());
    $elsewhere = Mutant::of(
        MutantId::hash(Path::of('src/Held.php'), PestCases::RUN_PLUS, '-held', 0),
        'n8',
        Location::of(Path::of('src/Held.php'), Line::of(3), Line::of(3)),
        Mutation::of(PestCases::RUN_PLUS, MutatorFamily::Arithmetic, '-held'),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );
    $invocation = MutationRequest::of(Paths::of(Path::of('src'), Path::of('lib')), WholeSuite::tests())
        ->leavingOut(Paths::of(Path::of('src/Held')))
        ->within(Seconds::of(42.0));
    $request = $invocation->narrowedTo(Paths::of(Path::of('src/Money.php'), Path::of('src/Held.php')), Narrowing::none()->toMutators(Mutators::named(PestCases::RUN_PLUS)));
    $retried = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())
        ->retry($invocation, Mutants::of($survivor, $gone, $elsewhere), Seconds::of(20.0));
    $notFound = Reason::that('Run again alone, Pest made no mutant with this id.');

    expect($retried)->toEqual(Mutants::of(
        $survivor,
        Mutant::of($id, 'n9', $place, $change, MutantStatus::Unjudged, Unmeasured::duration())->because($notFound),
        Interpretation::unjudged($elsewhere, $notFound),
    ))->and($shell->commands())->toEqual([
        PestCases::invocation()->mutation($request, WholeSuite::tests(), PestCases::results($at))->with(['MUTATION_GATE_ONLY' => sprintf('%s.only', PestCases::results($at))]),
    ])
        ->and(file_get_contents(sprintf('%s.only', PestCases::results($at))))->toBe("n1\nn9\nn8")
        ->and(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->retry($invocation, Mutants::none(), Seconds::of(20.0)))
        ->toEqual(Mutants::none());
});

it('runs mutants again on the canary group, reading the map the planning job handed the invocation, under the raised limit', function (): void {
    $at = PestCases::patched();
    $written = sprintf('%s/shared.coverage.php', dirname(PestCases::results($at)));
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? PestCases::listed($command)
        : PestCases::killed($command, $at));
    $invocation = PestCases::money()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    $retried = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->retry($invocation, Mutants::of(PestCases::mutant()), Seconds::of(20.0));

    expect($retried)->toEqual(Mutants::of(PestCases::mutant()))
        ->and($shell->commands()[1] ?? null)->toEqual(PestCases::invocation()->mutation(
            $invocation->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators(Mutators::named(PestCases::RUN_PLUS))),
            WholeSuite::tests(),
            PestCases::results($at),
        )->with([
            'MUTATION_GATE_SHARED_COVERAGE' => $written,
            'MUTATION_GATE_SUITE_SECONDS' => '3.250000',
            'MUTATION_GATE_CANARY' => 'mutation-canary',
            'MUTATION_GATE_ONLY' => sprintf('%s.only', PestCases::results($at)),
            'MUTATION_GATE_NARROW' => '1',
            'MUTATION_GATE_MUTANT_FLOOR' => '10.000000',
            'MUTATION_GATE_MUTANT_CAP' => '20.000000',
            ...TighterVariables::of(TighterSilence::standard()),
        ]));
});

it('runs a held unit\'s mutant again by the group that holds it, withholding what it is told to', function (): void {
    $at = PestCases::project();
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
    $holding = Group::named('holds:src/Money.php');
    $invocation = MutationRequest::of(Paths::of(Path::of('src')), $holding)->withholding(Withheld::of('DEPLOY_*'));
    $request = $invocation->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators(Mutators::named(PestCases::RUN_PLUS)));

    new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->retry($invocation, Mutants::of(PestCases::mutant()), Seconds::of(20.0));

    expect($shell->commands())->toEqual([
        PestCases::invocation()->mutation($request, $holding, PestCases::results($at))->with(['MUTATION_GATE_ONLY' => sprintf('%s.only', PestCases::results($at))]),
    ]);
});

it('hands each mutant run again its own result, though the mutants run again are numbered apart from the rest', function (): void {
    $at = PestCases::project();
    $money = sprintf('%s/src/Money.php', $at->root());
    $shell = new ShellFake(static function (Command $command) use ($money): Ran {
        $results = sprintf('%s', $command->environment()[GateVariable::Results->value] ?? '');
        CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', dirname($money, 2)), ['src/Money.php' => [20 => [0], 30 => [0]]], [PestCases::RUN_ADDS], []);
        PestRun::write($results, [
            PestRun::planned('pB', $money, 20, PestCases::RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
            PestRun::planned('pC', $money, 30, PestCases::RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
            PestRun::made(2),
            PestRun::killed('pB', PestCases::RUN_ADDS),
            PestRun::finished('pB', PestStatus::Tested, 0.25),
            PestRun::finished('pC', PestStatus::Untested, 0.5),
            PestRun::end(),
        ]);

        return Ran::finished(succeeded: true, output: '  Mutations: 1 untested, 1 tested');
    });
    $diff = Diff::fromPest(PestRun::diff('return $a + $b;', 'return $a - $b;'));
    // The same change on three lines: the first run numbers them 0, 1 and 2.
    $survivor = static fn(string $native, int $line, int $occurrence): Mutant => Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), PestCases::RUN_PLUS, $diff, $occurrence),
        $native,
        Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
        Mutation::of(PestCases::RUN_PLUS, MutatorFamily::Arithmetic, $diff),
        MutantStatus::Survived,
        Seconds::of(0.1),
    );
    $b = $survivor('pB', 20, 1);
    $c = $survivor('pC', 30, 2);

    $retried = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->retry(PestCases::money(), Mutants::of($b, $c), Seconds::of(20.0));
    $by = static fn(Mutants|CannotJudge $mutants): array => $mutants instanceof Mutants ? array_map(
        static fn(Mutant $mutant): array => [$mutant->id()->value(), $mutant->nativeId(), $mutant->status()],
        [...$mutants],
    ) : [];

    expect($by($retried))->toBe([
        [$b->id()->value(), 'pB', MutantStatus::Killed],
        [$c->id()->value(), 'pC', MutantStatus::Survived],
    ]);
});

it('hands each of the mutants that share Pest\'s id the one found again on its own line, and none found on no line of its own', function (int $one, int $two): void {
    $at = PestCases::project();
    $money = sprintf('%s/src/Money.php', $at->root());
    $shell = new ShellFake(static function (Command $command) use ($money, $one, $two): Ran {
        $results = sprintf('%s', $command->environment()[GateVariable::Results->value] ?? '');
        CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', dirname($money, 2)), ['src/Money.php' => [max(1, $one) => [0], max(1, $two) => [0]]], [PestCases::RUN_ADDS], []);
        PestRun::write($results, [
            PestRun::planned('pD', $money, $one, PestCases::RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
            PestRun::planned('pD', $money, $two, PestCases::RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
            PestRun::made(2),
            PestRun::finished('pD', PestStatus::Tested, 0.25),
            PestRun::finished('pD', PestStatus::Untested, 0.5),
            PestRun::end(),
        ]);

        return Ran::finished(succeeded: true, output: '  Mutations: 1 untested, 1 tested');
    });
    $diff = Diff::fromPest(PestRun::diff('return $a + $b;', 'return $a - $b;'));
    $survivor = static fn(int $line, int $occurrence): Mutant => Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), PestCases::RUN_PLUS, $diff, $occurrence),
        'pD',
        Location::of(Path::of('src/Money.php'), Line::of($line), Line::of($line)),
        Mutation::of(PestCases::RUN_PLUS, MutatorFamily::Arithmetic, $diff),
        MutantStatus::Survived,
        Seconds::of(0.1),
    );
    $retried = static fn(Mutant ...$asked): array => array_map(
        static fn(Mutant $mutant): array => [$mutant->id()->value(), $mutant->location()->start()->number(), $mutant->status()],
        [...(($again = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->retry(PestCases::money(), Mutants::of(...$asked), Seconds::of(20.0))) instanceof Mutants ? $again : Mutants::none())],
    );
    $first = $survivor($one, 0);
    $second = $survivor($two, 1);

    expect($retried($first, $second))->toBe([
        [$first->id()->value(), $one, MutantStatus::Killed],
        [$second->id()->value(), $two, MutantStatus::Survived],
    ])->and($retried($second))->toBe([[$second->id()->value(), $two, $one === $two ? MutantStatus::Killed : MutantStatus::Survived]])
        ->and($retried($survivor(60, 2), $first))->toBe([
            [$survivor(60, 2)->id()->value(), 60, MutantStatus::Unjudged],
            [$first->id()->value(), $one, MutantStatus::Killed],
        ]);
})->with([
    'on two lines' => [35, 40],
    'on one line' => [50, 50],
]);

it('runs a mutant a narrowed run killed with no killer again with every test file, before it counts', function (MutationRequest $request): void {
    $at = PestCases::project();
    $shell = PestCases::loadedNothing($at);

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);
    $narrow = array_map(
        static fn(Command $command): string|false|null => $command->environment()[GateVariable::Narrow->value] ?? null,
        $shell->commands(),
    );

    expect($result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): MutantStatus => $mutant->status(),
        [...$result->mutants()],
    ) : $result)->toBe([MutantStatus::Survived])
        ->and($narrow)->toBe(['1', false])
        ->and(file_get_contents(sprintf('%s.only', PestCases::results($at))))->toBe('n1');
})->with([
    'within a deadline' => [fn(): MutationRequest => PestCases::money()->within(Seconds::of(60.0))],
    'with no deadline' => [fn(): MutationRequest => PestCases::money()],
]);

/**
 * A shell whose narrowed run kills src/Money.php's line 11 with no killer, its process ending with code 2, and whose
 * run with every test file kills it again with code 255, or lets it survive; its control passes or fails.
 */
function adapterEndedTwice(Project $at, bool $killedAgain, bool $controlPasses = true): ShellFake
{
    return new ShellFake(static function (Command $command, int $before) use ($at, $killedAgain, $controlPasses): Ran {
        $results = sprintf('%s', $command->environment()[GateVariable::Results->value] ?? '');

        if ($results === '') {
            return Ran::finished(succeeded: $controlPasses, output: 'the control on the unmutated code');
        }

        CoverageMaps::write(Recorder::coverageBeside($results), sprintf('%s/', $at->root()), ['src/Money.php' => [11 => [0]]], [PestCases::RUN_ADDS], []);
        $killed = $before === 0 || $killedAgain;
        $status = $killed ? PestStatus::Tested : PestStatus::Untested;
        PestRun::write($results, [
            PestRun::planned('n1', sprintf('%s/src/Money.php', $at->root()), 11, PestCases::RUN_PLUS, 'return $a + $b;', 'return $a - $b;'),
            PestRun::made(1),
            ...($killed ? [PestRun::ended('n1', Ended::of($before === 0 ? 2 : 255, signalled: false, printed: sprintf('run %d', $before)))] : []),
            PestRun::finished('n1', $status, 0.25),
            PestRun::end(),
        ]);

        return Ran::finished(succeeded: true, output: sprintf('  Mutations: 1 %s', $status->value));
    });
}

it('gives a kill run again with every test file the evidence of that run, and none of the narrowed run it replaced', function (bool $killedAgain, bool $controlPasses, Evidence $expected): void {
    $at = PestCases::project();

    $result = new Pest($at, adapterEndedTwice($at, $killedAgain, $controlPasses), PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money());
    $mutants = $result instanceof MutationResult ? [...$result->mutants()] : [];

    expect($mutants)->toHaveCount(1)
        ->and($result instanceof MutationResult && $mutants !== [] ? $result->evidence()->of($mutants[0]->id()) : null)->toEqual($expected);
})->with([
    'killed again' => [true, true, fn(): Evidence => Evidence::none()->withEnded(Ended::unprinted(255, signalled: false))],
    'killed again, its control failing, so unjudged' => [true, false, fn(): Evidence => Evidence::none()],
    'surviving' => [false, true, fn(): Evidence => Evidence::none()],
]);

it('runs a mutant a narrowed run killed only by tests that errored again with every test file, before it counts', function (): void {
    $at = PestCases::project();
    $shell = PestCases::loadedNothing($at, PestRun::errored('n1', PestCases::RUN_ADDS), PestRun::errored('n1', 'T::subtracts'));

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money());

    expect($result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): MutantStatus => $mutant->status(),
        [...$result->mutants()],
    ) : $result)->toBe([MutantStatus::Survived])
        ->and($shell->commands())->toHaveCount(2);
});

it('counts a mutant a narrowed run killed where a test failed, whatever else errored', function (): void {
    $at = PestCases::project();
    $shell = PestCases::loadedNothing($at, PestRun::errored('n1', 'T::subtracts'), PestRun::killed('n1', PestCases::RUN_ADDS));

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money());

    expect($result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): MutantStatus => $mutant->status(),
        [...$result->mutants()],
    ) : $result)->toBe([MutantStatus::Killed])
        ->and($shell->commands())->toHaveCount(1);
});

it('counts a narrowed kill whose files\' tests pass alone on the unmutated code, running them once however many runs loaded them', function (): void {
    $at = PestCases::project();
    $shell = PestCases::narrowedKill($at, passAlone: true);
    $pest = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds());

    $first = $pest->mutate(PestCases::money());
    $again = $pest->mutate(PestCases::money());
    $baselines = array_values(array_filter(
        $shell->commands(),
        static fn(Command $command): bool => ($command->environment()[GateVariable::Results->value] ?? false) === false,
    ));

    expect([PestCases::statuses($first), PestCases::statuses($again)])->toBe([[MutantStatus::Killed], [MutantStatus::Killed]])
        ->and($shell->commands())->toHaveCount(3)
        ->and($baselines)->toHaveCount(1)
        ->and(array_slice($baselines[0]->arguments(), -1))->toBe([PestCases::spec($at)])
        ->and($baselines[0]->arguments())->toContain('--no-tia');
});

it('runs a narrowed kill whose files\' tests fail alone on the unmutated code again with every test file, before it counts', function (): void {
    $at = PestCases::project();
    $shell = PestCases::narrowedKill($at, passAlone: false);

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money()->within(Seconds::of(60.0)));

    expect(PestCases::statuses($result))->toBe([MutantStatus::Survived])
        ->and($shell->commands())->toHaveCount(3);
});

it('runs again only the narrowed kill whose own files\' tests fail alone, each set of files run on its own', function (): void {
    $at = PestCases::project();
    $shell = PestCases::narrowedKills($at, PestCases::spec($at));

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money());

    expect(PestCases::statuses($result))->toBe([MutantStatus::Killed, MutantStatus::Survived])
        ->and($shell->commands())->toHaveCount(4)
        ->and(file_get_contents(sprintf('%s.only', PestCases::results($at))))->toBe('n2');
});

it('runs the tests of every narrowed kill\'s files alone side by side, one place each, judging as one at a time would', function (): void {
    $at = PestCases::project();
    $shell = PestCases::narrowedKills($at, PestCases::spec($at));

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money());
    $baselines = array_values(array_filter(
        $shell->commands(),
        static fn(Command $command): bool => ($command->environment()[GateVariable::Results->value] ?? false) === false,
    ));

    expect(PestCases::statuses($result))->toBe([MutantStatus::Killed, MutantStatus::Survived])
        ->and($shell->batches())->toBe([2])
        ->and(array_map(static fn(Command $command): array => array_slice($command->arguments(), -1), $baselines))
        ->toBe([[PestCases::spec($at)], [sprintf('%s/tests/OtherSpec.php', $at->root())]]);
});

it('bounds the run of a narrowed kill\'s files alone, and its run again, by the time left of the deadline', function (): void {
    $at = PestCases::project();
    $shell = PestCases::narrowedKill($at, passAlone: false);
    $clock = new class implements Clock {
        private float $read = 0.0;

        public function seconds(): float
        {
            return $this->read += 10.0;
        }
    };

    new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds(), $clock)->mutate(PestCases::money()->within(Seconds::of(200.0)));

    expect(array_map(static fn(Command $command): Seconds|Unlimited => $command->deadline(), $shell->commands()))
        ->toEqual([Seconds::of(200.0), Seconds::of(90.0), Seconds::of(60.0)]);
});

it('names the steps a patched run\'s time went to, each timed from when the run began', function (): void {
    $at = PestCases::project();
    $shell = PestCases::narrowedKill($at, passAlone: false);
    $clock = new class implements Clock {
        private float $read = 0.0;

        public function seconds(): float
        {
            return $this->read += 10.0;
        }
    };

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds(), $clock)->mutate(PestCases::money());
    $steps = $result instanceof MutationResult ? array_map(
        static fn(StepTime $step): array => [$step->step(), $step->since()->seconds(), $step->took()->seconds(), $step->count()],
        [...$result->steps()],
    ) : $result;

    expect($steps)->toBe([
        [Step::Mutation, 20.0, 10.0, 1],
        [Step::Reading, 40.0, 10.0, 1],
        [Step::TrialCoverage, 60.0, 10.0, 1],
        [Step::Trials, 80.0, 10.0, 1],
        [Step::Baselines, 100.0, 10.0, 1],
        [Step::Confirmation, 120.0, 110.0, 1],
    ]);
});

it('leaves a narrowed kill unjudged where no time is left to run its files\' tests alone', function (): void {
    $at = PestCases::project();
    $shell = PestCases::narrowedKill($at, passAlone: true);
    $clock = new class implements Clock {
        private float $read = 0.0;

        public function seconds(): float
        {
            return $this->read += 10.0;
        }
    };

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds(), $clock)->mutate(PestCases::money()->within(Seconds::of(10.0)));

    expect(PestCases::statuses($result))->toBe([MutantStatus::Unjudged])
        ->and($shell->commands())->toHaveCount(1);
});

it('cannot judge a narrowed run whose run again with every test file failed', function (): void {
    $at = PestCases::project();
    $loaded = PestCases::loadedNothing($at);
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? $loaded->run($command)
        : Ran::finished(succeeded: false, output: 'broken'));

    expect(new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money()))
        ->toEqual(CannotJudge::because("Pest's mutation run failed. Pest said:\nbroken"));
});

it('leaves a mutant a narrowed run killed with no killer unjudged where no time is left to run it again', function (): void {
    $at = PestCases::project();
    $shell = PestCases::loadedNothing($at);
    $clock = new class implements Clock {
        private float $read = 0.0;

        public function seconds(): float
        {
            return $this->read += 10.0;
        }
    };

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds(), $clock)->mutate(PestCases::money()->within(Seconds::of(5.0)));
    $mutants = $result instanceof MutationResult ? [...$result->mutants()] : [];

    expect(array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), $mutants))->toBe([MutantStatus::Unjudged])
        ->and(array_map(static fn(Mutant $mutant): object => $mutant->reason(), $mutants))->toEqual([Reason::that(
            "Killed where its covering tests' files alone cannot vouch for the kill; no time is left to run them all.",
        )])
        ->and($shell->commands())->toHaveCount(1);
});

it('runs no mutant again that an unnarrowed run killed with no killer', function (): void {
    $at = PestCases::project();
    $shell = PestCases::loadedNothing($at);

    new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money());

    expect($shell->commands())->toHaveCount(1);
});

it('cannot judge a retry whose run failed', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'broken'));

    $retried = new Pest(PestCases::project(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())
        ->retry(PestCases::money(), Mutants::of(PestCases::mutant()), Seconds::of(20.0));

    expect($retried)->toEqual(CannotJudge::because("Pest's mutation run failed. Pest said:\nbroken"));
});
