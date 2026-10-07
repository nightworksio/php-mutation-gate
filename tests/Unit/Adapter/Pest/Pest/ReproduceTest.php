<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Bridges;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MemoryUnit;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\Unmade;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Mutators\PlusToMinus as AcmePlusToMinus;
use NightWorksIO\MutationGate\Tests\Support\PestCases;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;

afterEach(function (): void {
    Scratch::sweep();
});

it('reproduces a mutant in one run of its file with only its mutator, by the tests given, with what Pest printed', function (): void {
    $at = PestCases::project();
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
    $holding = Group::named('holds:src/Money.php');
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), $holding)
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators(Mutators::named(PestCases::RUN_PLUS)))
        ->withholding(Withheld::of('DEPLOY_*'));

    $reproduced = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())
        ->reproduce(Reproducible::of(PestCases::mutant()), MutationRequest::of(Paths::none(), $holding)->withholding(Withheld::of('DEPLOY_*')), Seconds::of(20.0));

    expect($reproduced instanceof Reproduction ? [$reproduced->mutant(), $reproduced->printed()] : $reproduced)->toEqual([PestCases::mutant(), '  Mutations: 1 tested'])
        ->and($shell->commands())->toEqual([PestCases::invocation()->mutation($request, $holding, PestCases::results($at))]);
});

it('reproduces a mutant under the cap its request carries, as the run it came from', function (): void {
    $at = PestCases::project();
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
    $request = MutationRequest::of(Paths::none(), WholeSuite::tests())->cappedAt(MemoryCap::of(256, MemoryUnit::Megabytes));

    new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->reproduce(Reproducible::of(PestCases::mutant()), $request, Seconds::of(20.0));

    expect(array_map(static fn(Command $command): mixed => $command->environment()[MemoryCap::SCAN_DIR] ?? null, $shell->commands()))
        ->toBe([MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), MemoryScan::directoryBeside(PestCases::results($at)))]);
});

it('reproduces a mutant patched Pest allows no more than the most it is given, above the floor', function (): void {
    $at = PestCases::patched();
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
    $request = MutationRequest::of(Paths::none(), WholeSuite::tests());

    new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->reproduce(Reproducible::of(PestCases::mutant()), $request, Seconds::of(20.0));

    expect(array_map(static fn(Command $command): array => [
        $command->environment()['MUTATION_GATE_MUTANT_FLOOR'] ?? null,
        $command->environment()['MUTATION_GATE_MUTANT_CAP'] ?? null,
    ], $shell->commands()))->toBe([['10.000000', '20.000000']]);
});

it('says Pest made no mutant with the id where the run no longer makes it', function (): void {
    $at = PestCases::project();
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
    $place = Location::of(Path::of('src/Money.php'), Line::of(12), Line::of(12));
    $change = Mutation::of(PestCases::RUN_PLUS, MutatorFamily::Arithmetic, '-gone');
    $gone = Mutant::of(MutantId::hash(Path::of('src/Money.php'), PestCases::RUN_PLUS, '-gone', 0), 'n9', $place, $change, MutantStatus::Survived, Unmeasured::duration());

    $reproduced = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())
        ->reproduce(Reproducible::of($gone), MutationRequest::of(Paths::none(), WholeSuite::tests())->withholding(Withheld::standard()), Seconds::of(20.0));

    expect($reproduced instanceof Reproduction ? $reproduced->mutant() : $reproduced)
        ->toEqual(Unmade::because(Reason::that('Run again alone, Pest made no mutant with this id.')));
});

it('cannot judge a reproduction whose run failed', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'broken'));

    $reproduced = new Pest(PestCases::project(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())
        ->reproduce(Reproducible::of(PestCases::mutant()), MutationRequest::of(Paths::none(), WholeSuite::tests())->withholding(Withheld::standard()), Seconds::of(20.0));

    expect($reproduced)->toEqual(CannotJudge::because("Pest's mutation run failed. Pest said:\nbroken"));
});

it('mutates nothing, and runs nothing, where no file is asked for', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $request = MutationRequest::of(Paths::none(), WholeSuite::tests());

    expect(new Pest(PestCases::project(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate($request))
        ->toEqual(MutationResult::of(Mutants::none(), 0))
        ->and($shell->commands())->toBe([]);
});

it('mutates nothing, and runs nothing, where Pest runs none of the mutators a request names', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $bridges = Bridges::to(Enabled::of(MutatorSet::of(AcmePlusToMinus::class)));
    $request = PestCases::money()->narrowedTo(PestCases::money()->files(), Narrowing::none()->toMutators(Mutators::named('default/UnwrapHtmlspecialchars')));

    expect(new Pest(PestCases::project(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds(), bridges: $bridges)->mutate($request))
        ->toEqual(MutationResult::of(Mutants::none(), 0))
        ->and($shell->commands())->toBe([]);
});

it('refuses a path with a comma, which Pest\'s lists of paths split on', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $pest = new Pest(PestCases::project(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds());
    $asked = MutationRequest::of(Paths::of(Path::of('src/a,b.php')), WholeSuite::tests());
    $leftOut = PestCases::money()->leavingOut(Paths::of(Path::of('src/c,d.php'), Path::of('src/e.php')));

    expect($pest->mutate($asked))->toEqual(CannotJudge::because(
        "Pest's --path and --ignore split on commas, so Pest cannot mutate src/a,b.php less .",
    ))->and($pest->mutate($leftOut))->toEqual(CannotJudge::because(
        "Pest's --path and --ignore split on commas, so Pest cannot mutate src/Money.php less src/c,d.php, src/e.php.",
    ))->and($shell->commands())->toBe([]);
});

it('cannot judge a run whose earlier results cannot be removed', function (): void {
    $at = PestCases::project();
    mkdir(PestCases::results($at), recursive: true);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money()))->toEqual(CannotJudge::because(sprintf(
        'An earlier run left %s or the map beside it, and the gate cannot remove them.',
        PestCases::results($at),
    )))->and($shell->commands())->toBe([]);
});

it('removes an earlier coverage run\'s map and log before it measures again', function (): void {
    $at = PestCases::project();
    $directory = sprintf('%s/.mutation-gate/coverage', $at->root());
    Scratch::write($at->root(), '.mutation-gate/coverage/coverage.php', '<?php return [];');
    Scratch::write($at->root(), '.mutation-gate/coverage/junit.xml', '<testsuites/>');
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $seen = [];
    $shell = new ShellFake(static function () use ($directory, &$seen): Ran {
        $seen = [is_file(sprintf('%s/coverage.php', $directory)), is_file(sprintf('%s/junit.xml', $directory))];

        return Ran::finished(succeeded: true, output: '');
    });

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->coverage($request))->toEqual(CannotJudge::because(
        sprintf('There is no coverage map at %s/coverage.php, so no test runs any line.', $directory),
    ))->and($seen)->toBe([false, false]);
});

it('cannot measure coverage where an earlier map cannot be removed', function (): void {
    $at = PestCases::project();
    mkdir(sprintf('%s/.mutation-gate/coverage/coverage.php', $at->root()), recursive: true);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->coverage($request))->toEqual(CannotJudge::because(sprintf(
        'An earlier run left %s/.mutation-gate/coverage/coverage.php or its JUnit log, '
        . 'and the gate cannot remove them.',
        $at->root(),
    )))->and($shell->commands())->toBe([]);
});

it('finds every @pest-mutate-ignore in the files asked for, running nothing', function (): void {
    $at = PestCases::project();
    Scratch::write($at->root(), 'src/Money.php', "<?php\n\n// @pest-mutate-ignore\n");
    $shell = ShellFake::answering(Ran::stopped(''));
    $markers = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->markers(Paths::of(Path::of('src')));

    expect(array_map(static fn(Marker $marker): string => $marker->where(), iterator_to_array($markers, preserve_keys: false)))
        ->toBe(['src/Money.php:3'])
        ->and($shell->commands())->toBe([]);
});
