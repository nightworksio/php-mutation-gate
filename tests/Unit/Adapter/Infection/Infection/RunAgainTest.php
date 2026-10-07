<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Clock;
use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\Unmade;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Tests\Support\InfectionCases;
use NightWorksIO\MutationGate\Tests\Support\InfectionRun;
use NightWorksIO\MutationGate\Tests\Support\InfectionShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('runs each mutant again by its unit\'s tests at the higher cap and with no deadline, whatever decided its limit', function (): void {
    $at = InfectionCases::project();
    $money = sprintf('%s/src/Money.php', $at->root());
    $first = InfectionCases::shell($at, [
        'timeouted' => [
            InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b'),
            InfectionRun::entry('Plus', $money, 40, '$c + $d', '$c - $d'),
        ],
        'escaped' => [InfectionRun::entry('Minus', $money, 12, '$a - $b', '$a + $b')],
    ]);
    $result = new Infection($at, $first, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $mutants = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $again = InfectionCases::shell($at, [
        'killed' => [InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b')],
        'escaped' => [InfectionRun::entry('Minus', $money, 12, '$a - $b', '$a + $b')],
    ]);
    $retried = new Infection($at, $again, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->retry(
            MutationRequest::of(Paths::of(Path::of('src')), Group::named('holds:src/Money.php'))->withholding(Withheld::of('DEPLOY_*')),
            $mutants,
            Seconds::of(12.0),
        );
    $generated = json_decode((string) file_get_contents($at->own('infection.json5')), associative: true);

    expect(InfectionCases::statuses($mutants))->toBe([MutantStatus::TimedOut, MutantStatus::Survived, MutantStatus::TimedOut])
        ->and(InfectionCases::statuses($retried))->toBe([MutantStatus::Killed, MutantStatus::Survived, MutantStatus::Unjudged])
        ->and(count($again->commands()))->toBe(3)
        ->and(InfectionCases::ran($again)[0])->toContain('--group=holds:src/Money.php')
        ->and(InfectionCases::ran($again)[1])->toContain('--test-framework-extra-args=--group="holds:src/Money.php"')
        ->and($again->commands()[1]->deadline())->toEqual(Unlimited::time())
        ->and(array_map(static fn(Command $command): Withheld => $command->withheld(), $again->commands()))
        ->each->toEqual(Withheld::standard()->and(Withheld::of('DEPLOY_*')))
        ->and(is_array($generated) ? [$generated['timeout'], $generated['mutators']] : [])->toBe([12.0, ['Minus' => true]]);
});

it('runs mutants again on the map the planning job handed the invocation, and runs no suite for them', function (): void {
    $at = InfectionCases::project();
    $money = sprintf('%s/src/Money.php', $at->root());
    $first = new Infection($at, InfectionCases::shell($at, [
        'timeouted' => [InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b')],
    ]), LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    InfectionCases::handedOn($at, 'planned');
    $again = InfectionCases::shell($at, InfectionCases::killed($at));
    $invocation = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    $retried = new Infection($at, $again, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->retry($invocation, $first instanceof MutationResult ? $first->mutants() : Mutants::none(), Seconds::of(12.0));

    expect(InfectionCases::statuses($retried))->toBe([MutantStatus::Killed])
        ->and(count($again->commands()))->toBe(1)
        ->and(InfectionCases::ran($again)[0])->toContain(sprintf('--coverage=%s/.gate/infection/coverage', $at->root()));
});

it('runs a retry on the coverage its mutation run left, and collects it again for other tests and for each mutation run', function (): void {
    $at = InfectionCases::project();
    $money = sprintf('%s/src/Money.php', $at->root());
    $shell = InfectionCases::shell($at, ['timeouted' => [InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b')]]);
    $infection = new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory());
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'));
    $coverageRuns = static fn(): int => count(array_filter(
        InfectionCases::ran($shell),
        static fn(array $arguments): bool => array_any($arguments, static fn(string $argument): bool => str_starts_with($argument, '--coverage-xml=')),
    ));
    $retried = static function (MutationRequest $asked) use ($infection, $request, $coverageRuns): int {
        $first = $infection->mutate($request);
        $infection->retry($asked, $first instanceof MutationResult ? $first->mutants() : Mutants::none(), Seconds::of(12.0));

        return $coverageRuns();
    };

    expect([
        $retried($request),
        $retried($request->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators(Mutators::named('Plus')))),
        $retried(MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Held.php'))),
        $retried($request->withholding(Withheld::of('DEPLOY_*'))),
    ])->toBe([1, 2, 4, 6]);
});

it('ends the runs of a retry, one after another, by the deadline its request set', function (): void {
    $at = InfectionCases::project();
    $money = sprintf('%s/src/Money.php', $at->root());
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->within(Seconds::of(100.0));
    $result = new Infection($at, InfectionCases::shell($at, [
        'timeouted' => [InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b')],
        'escaped' => [InfectionRun::entry('Minus', $money, 12, '$a - $b', '$a + $b')],
    ]), LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request);
    $again = InfectionCases::shell($at, InfectionCases::killed($at));
    $clock = new class implements Clock {
        private int $read = 0;

        public function nanoseconds(): int
        {
            return 10 * Seconds::NANOSECONDS * $this->read++;
        }
    };

    new Infection($at, $again, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory(), clock: $clock)
        ->retry($request, $result instanceof MutationResult ? $result->mutants() : Mutants::none(), Seconds::of(12.0));
    $runs = array_values(array_filter($again->commands(), static fn(Command $command): bool => $command->deadline() instanceof Seconds));

    expect(array_map(static fn(Command $command): Seconds|Unlimited => $command->deadline(), $runs))
        ->toEqual([Seconds::of(90.0), Seconds::of(80.0)]);
});

it('runs nothing again for no mutant, and cannot judge a retry whose runs fail', function (): void {
    $at = InfectionCases::project();
    $result = new Infection($at, InfectionCases::shell($at, [
        'timeouted' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')],
    ]), LimitBounds::between(Seconds::of(4.0), Seconds::of(4.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $mutants = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $idle = InfectionCases::shell($at, []);
    $refused = InfectionCases::project('{"testFramework": "phpspec"}');

    $retried = static fn(Project $project, InfectionShellFake $shell, Mutants $asked): Mutants|CannotJudge => new Infection(
        $project,
        $shell,
        LimitBounds::between(Seconds::of(4.0), Seconds::of(4.0)),
        nativeMarkersAllowed: false,
        files: new CapDirectory(),
    )->retry(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $asked, Seconds::of(8.0));

    expect($retried($at, $idle, Mutants::none()))->toEqual(Mutants::none())
        ->and($idle->commands())->toBe([])
        ->and($retried($at, InfectionCases::shell($at, [], covers: false), $mutants))
        ->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nsaid"))
        ->and($retried($at, InfectionCases::shell($at, [], logs: false), $mutants))
        ->toEqual(CannotJudge::because("Infection wrote no log, so no mutant it ran has a result. Infection said:\nsaid"))
        ->and($retried($refused, InfectionCases::shell($refused, []), $mutants))
        ->toBeInstanceOf(CannotJudge::class);
});

it('reproduces a mutant alone with only its mutator, by its unit\'s tests at the limit, whatever its first limit was', function (): void {
    $at = InfectionCases::project();
    $money = sprintf('%s/src/Money.php', $at->root());
    $result = new Infection($at, InfectionCases::shell($at, ['timeouted' => [InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b')]]), LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $timedOut = null;

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $timedOut = $mutant;
    }

    $again = InfectionCases::shell($at, InfectionCases::killed($at));
    $reproduced = $timedOut instanceof Mutant ? new Infection($at, $again, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->reproduce(Reproducible::of($timedOut), MutationRequest::of(Paths::none(), Group::named('holds:src/Money.php'))->withholding(Withheld::of('DEPLOY_*')), Seconds::of(12.0)) : null;
    $generated = json_decode((string) file_get_contents($at->own('infection.json5')), associative: true);

    expect($timedOut instanceof Mutant ? $timedOut->status() : $timedOut)->toBe(MutantStatus::TimedOut)
        ->and($reproduced instanceof Reproduction && $reproduced->mutant() instanceof Mutant ? [$reproduced->mutant()->status(), $reproduced->printed()] : $reproduced)
        ->toBe([MutantStatus::Killed, 'said'])
        ->and(count($again->commands()))->toBe(2)
        ->and(InfectionCases::ran($again)[0])->toContain('--group=holds:src/Money.php')
        ->and(InfectionCases::ran($again)[1])->toContain('--test-framework-extra-args=--group="holds:src/Money.php"')
        ->and(array_map(static fn(Command $command): Withheld => $command->withheld(), $again->commands()))
        ->each->toEqual(Withheld::standard()->and(Withheld::of('DEPLOY_*')))
        ->and(is_array($generated) ? [$generated['timeout'], $generated['mutators']] : [])->toBe([12.0, ['Plus' => true]]);
});

it('says Infection made no mutant with the id where it no longer makes it, and cannot judge where its config, coverage or run fails', function (): void {
    $at = InfectionCases::project();
    $gone = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Minus', '-gone', 0),
        '',
        Location::of(Path::of('src/Money.php'), Line::of(12), Line::of(12)),
        Mutation::of('Minus', MutatorFamily::Arithmetic, '-gone'),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );
    $refused = InfectionCases::project('{"testFramework": "phpspec"}');
    $reproduced = static fn(Project $project, InfectionShellFake $shell): Reproduction|CannotJudge => new Infection(
        $project,
        $shell,
        LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)),
        nativeMarkersAllowed: false,
        files: new CapDirectory(),
    )->reproduce(Reproducible::of($gone), MutationRequest::of(Paths::none(), WholeSuite::tests())->withholding(Withheld::standard()), Seconds::of(6.0));
    $unjudged = $reproduced($at, InfectionCases::shell($at, InfectionCases::killed($at)));

    expect($unjudged instanceof Reproduction ? $unjudged->mutant() : $unjudged)
        ->toEqual(Unmade::because(Reason::that('Run again, Infection made no mutant with this id.')))
        ->and($reproduced($at, InfectionCases::shell($at, [], covers: false)))
        ->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nsaid"))
        ->and($reproduced($at, InfectionCases::shell($at, [], logs: false)))
        ->toEqual(CannotJudge::because("Infection wrote no log, so no mutant it ran has a result. Infection said:\nsaid"))
        ->and($reproduced($refused, InfectionCases::shell($refused, [])))
        ->toBeInstanceOf(CannotJudge::class);
});
