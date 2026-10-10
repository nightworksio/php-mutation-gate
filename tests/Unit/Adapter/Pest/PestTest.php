<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Ceiling;
use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Invocation;
use NightWorksIO\MutationGate\Adapter\Pest\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Pest\Order\Plan;
use NightWorksIO\MutationGate\Adapter\Pest\Patch;
use NightWorksIO\MutationGate\Adapter\Pest\Patching;
use NightWorksIO\MutationGate\Adapter\Pest\Pest;
use NightWorksIO\MutationGate\Adapter\Pest\PrunedFile;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\Analysis\NoPreCheck;
use NightWorksIO\MutationGate\Core\Analysis\PreChecker;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\Config\Triage;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\ControlRuns;
use NightWorksIO\MutationGate\Core\Control\Controls;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\PhpReport;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Order\Kills;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Order\Ranking;
use NightWorksIO\MutationGate\Core\Pruning\MutatorNames;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\PcovReach;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Runner\TighterVariables;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\Described;
use NightWorksIO\MutationGate\Tests\Support\MutatePlugin;
use NightWorksIO\MutationGate\Tests\Support\PestCases;
use NightWorksIO\MutationGate\Tests\Support\PreCheckerFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\ShellFake;
use NightWorksIO\MutationGate\Tests\Support\Unexecutables;

afterEach(function (): void {
    Scratch::sweep();
});

it('names Pest, the exact versions it mutates with, and the PHP it runs on', function (): void {
    $at = PestCases::project();
    $installed = ['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'phpunit/php-code-coverage'];
    $packages = array_map(
        static fn(string $name): array => ['name' => $name, 'version' => '1.0.0', 'dist' => ['reference' => 'abc']],
        $installed,
    );
    Scratch::write($at->root(), 'vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));

    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: Described::output()));
    $pest = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds());
    $withheld = Withheld::of('DEPLOY_*');

    expect($pest->identity($withheld))->toEqual(Identity::of(
        'pest',
        Versions::of(...array_map(static fn(string $name): Version => Version::of($name, '1.0.0', 'abc'), $installed)),
        Described::platform()->digest(),
    ))
        ->and($pest->identity($withheld))->toEqual($pest->identity($withheld))
        ->and($shell->commands())->toEqual([Command::php($withheld, ...Platform::describing())]);
});

it('cannot say which Pest it runs where the PHP it starts does not describe itself', function (): void {
    $at = PestCases::project();
    $packages = array_map(
        static fn(string $name): array => ['name' => $name, 'version' => '1.0.0'],
        ['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'phpunit/php-code-coverage'],
    );
    Scratch::write($at->root(), 'vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'Segmentation fault'));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->identity(Withheld::standard()))->toEqual(CannotJudge::because(
        'The PHP the runner starts could not describe itself, so no proof can be keyed: '
        . "it printed no description of itself:\nSegmentation fault",
    ));
});

it('cannot say which Pest it runs where Composer installed none', function (): void {
    $at = PestCases::project();
    $shell = ShellFake::answering(Ran::stopped(''));
    $pest = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds());

    expect($pest->identity(Withheld::standard()))->toEqual(CannotJudge::because(sprintf(
        '%s/vendor/composer/installed.json does not list pestphp/pest, pestphp/pest-plugin-mutate, phpunit/phpunit, '
        . 'phpunit/php-code-coverage, so the gate cannot say which Pest judges the mutants. Run composer install.',
        $at->root(),
    )));
});

it('lists the suite\'s groups as Pest lists them', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: PestCases::RUN_LISTING));

    $groups = new Pest(PestCases::project(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->groups(Withheld::of('CI_JOB_TOKEN'));

    expect($groups)->toEqual(Groups::of(Group::named('mutation-canary')))
        ->and($shell->commands())->toEqual([PestCases::invocation()->listingGroups(Withheld::of('CI_JOB_TOKEN'))]);
});

it('runs the suite under coverage into a directory it makes, with pcov collecting from the whole project, and reads the map', function (): void {
    $at = PestCases::project();
    $shell = new ShellFake(static function () use ($at): Ran {
        $lines = ['src/Money.php' => [11 => [0]]];
        PestCases::map($at->root(), '.mutation-gate/coverage/coverage.php', $lines, [PestCases::RUN_ADDS => 0.5]);

        return Ran::finished(succeeded: true, output: 'OK');
    });
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $directory = sprintf('%s/.mutation-gate/coverage', $at->root());

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->coverage($request))->toEqual(CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of(PestCases::RUN_ADDS))
        ->timed(TestId::of(PestCases::RUN_ADDS), Seconds::of(0.5)))
        ->and($shell->commands())
        ->toEqual([PestCases::invocation()->coverage($request, $directory, PcovReach::under($at->root(), Path::of('vendor')))]);
});

it('reads the map a coverage run this job started itself left in a directory, running nothing', function (): void {
    $at = PestCases::project();
    mkdir(sprintf('%s/build/suite', $at->root()), recursive: true);
    PestCases::map($at->root(), 'build/suite/coverage.php', ['src/Money.php' => [11 => [0]]], [PestCases::RUN_ADDS => 0.5]);
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'not run'));
    $pest = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds());

    expect($pest->coverage(CoverageRan::in(Path::of('build/suite'))))->toEqual(CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of(PestCases::RUN_ADDS))
        ->timed(TestId::of(PestCases::RUN_ADDS), Seconds::of(0.5)))
        ->and($pest->coverage(CoverageRan::in(Path::of('build/none'))))
        ->toEqual(PhpReport::missingAt(sprintf('%s/build/none/coverage.php', $at->root())))
        ->and($shell->commands())->toBe([])
        ->and(sprintf('%s/build/none', $at->root()))->not->toBeDirectory();
});

it('cannot judge a coverage run that failed, with what Pest said', function (): void {
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'No code coverage driver'));

    expect(new Pest(PestCases::project(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->coverage($request))
        ->toEqual(CannotJudge::because("Pest's coverage run failed. Pest said:\nNo code coverage driver"));
});

it('times a run of no test, started as a mutant\'s own run of a file whose mutant is an unchanged copy', function (): void {
    $at = PestCases::project();
    Scratch::write($at->root(), 'src/Money.php', '<?php // money');
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: 'No tests found.')->took(Seconds::of(1.8)));
    $copy = sprintf('%s/.mutation-gate/pest/start-up/Money.php', $at->root());

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->startUp(Path::of('src/Money.php'), Withheld::of('DEPLOY_*')))
        ->toEqual(Seconds::of(1.8))
        ->and($shell->commands())->toEqual([
            PestCases::invocation()->startingUp(Withheld::of('DEPLOY_*'), sprintf('%s/src/Money.php', $at->root()), $copy),
        ])
        ->and(file_get_contents($copy))->toBe('<?php // money');
});

it('cannot judge a run of no test that failed, with what Pest said, or one of a file that is not there', function (): void {
    $at = PestCases::project();
    Scratch::write($at->root(), 'src/Money.php', '<?php');
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'Fatal error')->took(Seconds::of(0.4)));
    $pest = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds());

    expect($pest->startUp(Path::of('src/Money.php'), Withheld::standard()))
        ->toEqual(CannotJudge::because("Pest's run of no test, timing a mutant's start-up, failed. Pest said:\nFatal error"))
        ->and($pest->startUp(Path::of('src/Gone.php'), Withheld::standard()))
        ->toEqual(CannotJudge::because("Pest's run of no test needs an unchanged copy of src/Gone.php, and it could not be made."));
});

it('cannot time a run of no test where an earlier copy cannot be removed', function (): void {
    $at = PestCases::project();
    Scratch::write($at->root(), 'src/Money.php', '<?php');
    $copy = sprintf('%s/.mutation-gate/pest/start-up/Money.php', $at->root());
    mkdir($copy, recursive: true);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->startUp(Path::of('src/Money.php'), Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf('An earlier run left %s, and the gate cannot remove it.', $copy)))
        ->and($shell->commands())->toBe([]);
});

it('reads the gate\'s own map another job handed over, running nothing', function (): void {
    $at = PestCases::project();
    $map = CoverageMap::empty()->covered(Path::of('src/Held.php'), Line::of(5), TestId::of(PestCases::RUN_ADDS));
    PestCases::handedOver($at, 'planned', $map);
    $shell = ShellFake::answering(Ran::finished(succeeded: false, output: 'not run'));
    $pest = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds());

    expect($pest->coverage(CoverageRead::from(Path::of('planned'))))->toEqual($map)
        ->and($shell->commands())->toBe([]);
});

it('never reads a runner\'s map another job wrote, which is PHP that reading runs', function (): void {
    $at = PestCases::project();
    Scratch::write($at->root(), 'planned/coverage.php', '<?php throw new RuntimeException(\'ran\');');
    $pest = new Pest($at, ShellFake::answering(Ran::finished(succeeded: false, output: '')), Patching::off(), new CapDirectory(), Triage::standard()->bounds());

    expect($pest->coverage(CoverageRead::from(Path::of('planned'))))->toEqual(CannotJudge::because(sprintf(
        'The gate wrote no coverage map at %s/planned/map.json.gz, and reads no runner\'s map another job wrote.',
        $at->root(),
    )));
});

it('names the tests of a map that some test files hold, by the class Pest declares for each', function (): void {
    $at = PestCases::project();
    Scratch::write($at->root(), 'tests/MoneySpec.php', '<?php');
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of(PestCases::RUN_ADDS))
        ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('P\Tests\HeldSpec::__pest_evaluable_it_holds'));
    $pest = new Pest($at, ShellFake::answering(Ran::stopped('')), Patching::off(), new CapDirectory(), Triage::standard()->bounds());

    expect($pest->testsIn(Paths::of(Path::of('tests/MoneySpec.php')), $map))->toEqual(TestIds::of(TestId::of(PestCases::RUN_ADDS)));
});

it('names the test files a covering test\'s filter selects, or all when it will not fit', function (): void {
    $at = PestCases::project();
    Scratch::write($at->root(), 'tests/MoneySpec.php', '<?php');
    Scratch::write($at->root(), 'tests/HeldSpec.php', '<?php');
    $long = sprintf('P\Tests\HeldSpec::__pest_evaluable_%s', str_repeat('x', Ceiling::BYTES));
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of(PestCases::RUN_ADDS))
        ->covered(Path::of('src/Kernel.php'), Line::of(3), TestId::of($long));
    $pest = new Pest($at, ShellFake::answering(Ran::stopped('')), Patching::off(), new CapDirectory(), Triage::standard()->bounds());
    $every = Paths::of(Path::of('tests/HeldSpec.php'), Path::of('tests/MoneySpec.php'));

    expect($pest->judges(Path::of('src/Money.php'), $map))->toEqual(Paths::of(Path::of('tests/MoneySpec.php')))
        ->and($pest->judges(Path::of('src/Kernel.php'), $map))->toEqual($every)
        ->and($pest->judges(Path::of('src/Nowhere.php'), $map))->toEqual(Paths::none());
});

it('refuses to judge by a filter, which Pest cannot select held tests by', function (): void {
    $shell = ShellFake::answering(Ran::stopped(''));
    $request = MutationRequest::of(Paths::of(Path::of('src/Kernel.php')), Filter::matching('KernelTest'));

    expect(new Pest(PestCases::project(), $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate($request))->toEqual(CannotJudge::because(
        'Pest selects held tests by the holds: groups its plugin adds for #[Holds], not by the filter KernelTest.',
    ))->and($shell->commands())->toBe([]);
});

it('mutates with a fresh results file, and reads what the plugin recorded', function (): void {
    $at = PestCases::project();
    Scratch::write($at->root(), '.mutation-gate/pest/results.jsonl', 'an earlier run');
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));

    $result = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money());

    expect(PestCases::untimed($result))->toEqual(MutationResult::of(Mutants::of(PestCases::mutant()), 0))
        ->and($shell->commands())
        ->toEqual([PestCases::invocation()->mutation(PestCases::money(), WholeSuite::tests(), PestCases::results($at))]);
});

it('keeps every PHP process of a capped run to the cap, through an ini file beside the results it removes once done', function (): void {
    $at = PestCases::project();
    $read = [];
    $shell = new ShellFake(static function (Command $command) use ($at, &$read): Ran {
        $read[] = (string) file_get_contents(sprintf('%s/%s', MemoryScan::directoryBeside(PestCases::results($at)), MemoryCap::FILE));

        return PestCases::killed($command, $at);
    });
    $capped = PestCases::money()->cappedAt(MemoryCap::standard());
    $directory = MemoryScan::directoryBeside(PestCases::results($at));

    $result = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate($capped);

    expect(PestCases::untimed($result))->toEqual(MutationResult::of(Mutants::of(PestCases::mutant()), 0))
        ->and($shell->commands())->toEqual([
            PestCases::invocation()->mutation($capped, WholeSuite::tests(), PestCases::results($at))->with([
                MemoryCap::SCAN_DIR => MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), $directory),
            ]),
        ])
        ->and($read)->toBe(["memory_limit=1G\n"])
        ->and(is_dir($directory))->toBeFalse();
});

it('cannot judge a capped run whose cap cannot be written', function (): void {
    $at = PestCases::project();
    mkdir(sprintf('%s/%s', MemoryScan::directoryBeside(PestCases::results($at)), MemoryCap::FILE), recursive: true);
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money()->cappedAt(MemoryCap::standard())))
        ->toEqual(CannotJudge::because(sprintf(
            MemoryCap::UNWRITTEN,
            sprintf('%s/%s', MemoryScan::directoryBeside(PestCases::results($at)), MemoryCap::FILE),
        )))
        ->and($shell->commands())->toBe([]);
});

it('puts the likely killers first where the request asks, handing the plugin the history in a fresh order directory', function (): void {
    $at = PestCases::project();
    $order = sprintf('%s/.mutation-gate/order', $at->root());
    Scratch::write($at->root(), '.mutation-gate/order/m1/test-run-history', 'an earlier run');
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
    $history = KillHistory::none()->withFunction(
        Enclosing::named(Path::of('src/Money.php'), 'add'),
        Ranking::of(Kills::of(TestId::of(PestCases::RUN_ADDS), 1)),
    );
    $request = PestCases::money()->searching(KillSearch::of(Ordering::of(TestOrder::KillersFirst, $history), MatrixKind::FirstKiller));

    new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);

    expect($shell->commands())->toEqual([
        PestCases::invocation()->mutation($request, WholeSuite::tests(), PestCases::results($at))->with([GateVariable::Order->value => $order]),
    ])->and(Plan::read($order))->toEqual($history)
        ->and(is_file(sprintf('%s/m1/test-run-history', $order)))->toBeFalse();
});

it('hands the patched plugin the mutators the request leaves out of its unchanged files, in a list beside the results', function (): void {
    $at = PestCases::project();
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
    $pruned = Pruned::of(MutatorNames::of(PestCases::RUN_PLUS), Paths::of(Path::of('src/Money.php')));
    $request = PestCases::money()->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->pruning($pruned));
    $list = sprintf('%s.pruned', PestCases::results($at));

    new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);

    expect($shell->commands())->toEqual([
        PestCases::invocation()->mutation($request, WholeSuite::tests(), PestCases::results($at))->with([GateVariable::Pruned->value => $list]),
    ])->and(PrunedFile::leavesOut($list, sprintf('%s/src/Money.php', $at->root()), PestCases::RUN_PLUS))->toBeTrue();
});

it('hands a patched plugin\'s mutants to static analysis before their tests, where the gate checks them so, and never an unpatched one\'s', function (): void {
    $told = static function (Patching $patching, PreChecker $checker): array {
        $at = PestCases::project();
        $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
        new Pest($at, $shell, $patching, new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money(), $checker);

        return array_map(
            static fn(Command $command): string => (string) ($command->environment()[GateVariable::Verdicts->value] ?? ''),
            $shell->commands(),
        );
    };
    $on = Patching::on(Group::named('mutation-canary'));
    $patched = $told($on, new PreCheckerFake([]));

    expect($patched[0])->toEndWith('/results.jsonl.verdicts')
        ->and($told($on, new NoPreCheck()))->toBe(array_fill(0, count($patched), ''))
        ->and($told(Patching::off(), new PreCheckerFake([]))[0])->toBe('');
});

it('cannot judge where an earlier run\'s orders cannot be removed', function (): void {
    $at = PestCases::project();
    mkdir(sprintf('%s/.mutation-gate/order/plan.json', $at->root()), recursive: true);
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
    $request = PestCases::money()->searching(KillSearch::of(Ordering::of(TestOrder::KillersFirst, KillHistory::none()), MatrixKind::FirstKiller));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate($request))->toEqual(CannotJudge::because(sprintf(
        'An earlier run left orders in %s/.mutation-gate/order, and the gate cannot remove them.',
        $at->root(),
    )))->and($shell->commands())->toBe([]);
});

it('mutates against a group without reading a shared map', function (): void {
    $at = PestCases::project();
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
    $held = Group::named('holds:src/Money.php');
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), $held)->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);

    expect($shell->commands())->toEqual([
        PestCases::invocation()->mutation($request, $held, PestCases::results($at))->with(['MUTATION_GATE_NARROW' => '1', 'MUTATION_GATE_MUTANT_FLOOR' => '10.000000', 'MUTATION_GATE_MUTANT_CAP' => '300.000000', ...TighterVariables::of(TighterSilence::standard())]),
    ]);
});

it('tells a patched run the start-up the request\'s pool measured, which each mutant\'s limit is laid on', function (): void {
    $at = PestCases::project();
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
    $held = Group::named('holds:src/Money.php');
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), $held)
        ->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')))
        ->across(Pool::single()->startingIn(Seconds::of(2.5)));

    new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);

    expect($shell->commands()[0]->environment())->toMatchArray(['MUTATION_GATE_MUTANT_START_UP' => '2.500000']);
});

it('opens a patched shard on the canary group, with the planning job\'s map written again for its Pest', function (): void {
    $at = PestCases::patched();
    $written = sprintf('%s/shared.coverage.php', dirname(PestCases::results($at)));
    $loaded = null;
    $shell = new ShellFake(static function (Command $command, int $before) use ($at, $written, &$loaded): Ran {
        $loaded ??= is_file($written) ? CoverageFile::at($written) : null;

        return $before === 0 ? Ran::finished(succeeded: true, output: PestCases::RUN_LISTING) : PestCases::killed($command, $at);
    });
    $request = PestCases::money()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));
    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);

    expect($result)->toBeInstanceOf(MutationResult::class)
        ->and($result instanceof MutationResult ? array_slice(array_map(
            static fn(StepTime $step): Step => $step->step(),
            [...$result->steps()],
        ), 0, 2) : [])->toBe([Step::Coverage, Step::Mutation])
        ->and($loaded instanceof CoverageFile ? $loaded->map($at) : $loaded)->toEqual(
            CoverageMap::of(CoveredLine::of(Path::of('src/Money.php'), 11, PestCases::RUN_ADDS))
                ->timedEach(TimedTest::of(PestCases::RUN_ADDS, 1.25), TimedTest::of('Tests\B::c', 2.0)),
        )
        ->and($shell->commands())->toEqual([
            PestCases::invocation()->listingGroups(Withheld::standard()),
            PestCases::invocation()->mutation($request, WholeSuite::tests(), PestCases::results($at))->with([
                'MUTATION_GATE_SHARED_COVERAGE' => $written,
                'MUTATION_GATE_SUITE_SECONDS' => '3.250000',
                'MUTATION_GATE_CANARY' => 'mutation-canary',
                'MUTATION_GATE_NARROW' => '1',
                'MUTATION_GATE_MUTANT_FLOOR' => '10.000000',
                'MUTATION_GATE_MUTANT_CAP' => '300.000000',
                ...TighterVariables::of(TighterSilence::standard()),
            ]),
        ]);
});

it('runs a patched shard\'s canary group again alone where its opening run failed no test and still failed', function (): void {
    $at = PestCases::patched();
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? Ran::finished(succeeded: true, output: PestCases::RUN_LISTING)
        : Ran::exited(1, "  Tests:    1 passed (1 assertions)\n"));
    $request = PestCases::money()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);

    expect($result)->toBeInstanceOf(CannotJudge::class)
        ->and($shell->commands())->toHaveCount(3)
        ->and($shell->commands()[2])->toEqual(
            PestCases::invocation()
                ->opening($request, Group::named('mutation-canary'), sprintf('%s.events', PestCases::results($at)))
                ->within(Unlimited::time()),
        );
});

it('runs the held tests again alone where a patched run against them failed none and still failed', function (): void {
    $at = PestCases::patched();
    $shell = new ShellFake(static fn(): Ran => Ran::exited(1, "  Tests:    1 passed (1 assertions)\n"));
    $held = Group::named('holds:src/Money.php');
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), $held)
        ->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request);

    expect($shell->commands())->toHaveCount(2)
        ->and($shell->commands()[1])->toEqual(
            PestCases::invocation()->opening($request, $held, sprintf('%s.events', PestCases::results($at)))->within(Unlimited::time()),
        );
});

it('judges a patched shard\'s mutant on a line that is not executable by the tests the plan\'s whole map says read its value, within the cap where the map timed none of them', function (): void {
    $at = Unexecutables::project();
    MutatePlugin::pristine()->into(sprintf('%s/vendor', $at->root()));
    Patch::applyIn(sprintf('%s/vendor', $at->root()));
    $other = CoveredLine::of(Path::of('src/Money.php'), 14, 'P\\Tests\\OtherSpec::__pest_evaluable_it_runs_the_other');
    $internal = CoveredLine::of(Path::of('src/Money.php'), 10, 'P\\Tests\\InternalSpec::__pest_evaluable_it_runs');
    PestCases::handedOver($at, 'own', CoverageMap::of($other));
    PestCases::handedOver($at, 'whole', CoverageMap::of($other, $internal));
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => match (true) {
        $before === 0 => Ran::finished(succeeded: true, output: PestCases::RUN_LISTING),
        in_array('--mutate', $command->arguments(), strict: true) => Ran::finished(
            succeeded: Unexecutables::run($at, ['internal']) !== '',
            output: '  Mutations: 1 uncovered',
        ),
        default => Unexecutables::answering($command, ['tests/InternalSpec.php']),
    });
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->reusingCoverage(Handed::maps(Path::of('own'), Path::of('whole')));

    $result = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), LimitBounds::between(Seconds::of(7.0), Seconds::of(7.0)))->mutate($request);
    $trials = array_slice($shell->commands(), 2);

    expect($result instanceof MutationResult ? array_map(
        static fn(Mutant $mutant): MutantStatus => $mutant->status(),
        [...$result->mutants()],
    ) : $result)->toBe([MutantStatus::Killed])
        ->and(array_map(static fn(Command $command): Seconds|Unlimited => $command->deadline(), $trials))
        ->toEqual([Seconds::of(7.0), Seconds::of(7.0)]);
});

it('cannot judge a run whose plugin wrote no results, and judges no mutant left uncovered', function (): void {
    $at = Unexecutables::project();
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: '  Mutations: 1 uncovered'));

    expect(new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())))
        ->toEqual(CannotJudge::because(sprintf(
            'Pest wrote no results to %s/.mutation-gate/pest/results.jsonl. Is pestphp/pest-plugin allowed to run in composer.json?',
            $at->root(),
        )))
        ->and($shell->commands())->toHaveCount(1);
});

it('cannot judge a patched shard\'s run without the plan\'s whole map', function (): void {
    $at = PestCases::patched();
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? Ran::finished(succeeded: true, output: PestCases::RUN_LISTING)
        : PestCases::killed($command, $at));
    $request = PestCases::money()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('whole')));

    expect(new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request))
        ->toEqual(CoverageMapFile::missingAt($at->absolute(CoverageMapFile::in(Path::of('whole')))));
});

it('opens a shard on its own suite unpatched, or when it collects its own map', function (): void {
    $at = PestCases::patched();
    $shell = new ShellFake(static fn(Command $command): Ran => PestCases::killed($command, $at));
    $reusing = PestCases::money()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->mutate($reusing);
    new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate(PestCases::money());

    expect($shell->commands())->toEqual([
        PestCases::invocation()->mutation($reusing, WholeSuite::tests(), PestCases::results($at)),
        PestCases::invocation()->mutation(PestCases::money(), WholeSuite::tests(), PestCases::results($at))
            ->with(['MUTATION_GATE_NARROW' => '1', 'MUTATION_GATE_MUTANT_FLOOR' => '10.000000', 'MUTATION_GATE_MUTANT_CAP' => '300.000000', ...TighterVariables::of(TighterSilence::standard())]),
    ]);
});

it('cannot open a shard on the canary group without the patch applied', function (): void {
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: PestCases::RUN_LISTING));
    $request = PestCases::money()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    expect(new Pest(PestCases::project(), $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request))->toEqual(CannotJudge::because(
        'pest.patch is on, but pest-plugin-mutate in vendor is not patched. Run mutation-gate pest:patch.',
    ))->and($shell->commands())->toBe([]);
});

it('finds Pest, what Composer installed and the patch in the vendor directory the project installs into', function (): void {
    $at = PestCases::patched('lib/vendor');
    $installed = ['pestphp/pest', 'pestphp/pest-plugin-mutate', 'phpunit/phpunit', 'phpunit/php-code-coverage'];
    $packages = array_map(static fn(string $name): array => ['name' => $name, 'version' => '1.0.0'], $installed);
    Scratch::write($at->root(), 'lib/vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
    $shell = new ShellFake(static fn(Command $command, int $before): Ran => match ($before) {
        0 => Ran::finished(succeeded: true, output: PestCases::RUN_LISTING),
        1 => Ran::finished(succeeded: true, output: Described::output()),
        default => PestCases::killed($command, $at),
    });
    $pest = new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds());
    $invocation = Invocation::installedIn(Path::of('lib/vendor'));

    expect($pest->groups(Withheld::standard()))->toBeInstanceOf(Groups::class)
        ->and($pest->identity(Withheld::standard()))->toBeInstanceOf(Identity::class)
        ->and($pest->mutate(PestCases::money()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')))))->toBeInstanceOf(MutationResult::class)
        ->and($shell->commands()[0])->toEqual($invocation->listingGroups(Withheld::standard()));
});

it('cannot open a shard on a canary group with no test, or one it cannot list', function (): void {
    $at = PestCases::patched();
    $request = PestCases::money()->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));
    $empty = ShellFake::answering(Ran::finished(succeeded: true, output: PestCases::RUN_LISTING));
    $unlisted = ShellFake::answering(Ran::finished(succeeded: false, output: 'broken'));
    $other = Patching::on(Group::named('canary'));

    expect(new Pest($at, $empty, $other, new CapDirectory(), Triage::standard()->bounds())->mutate($request))
        ->toEqual(CannotJudge::because('pest.patch is on, but the canary group canary holds no test. Add one.'))
        ->and(new Pest($at, $unlisted, $other, new CapDirectory(), Triage::standard()->bounds())->mutate($request))
        ->toEqual(CannotJudge::because(
            "Pest did not list the suite's groups, so no group can hold a path. Pest said:\nbroken",
        ));
});

it('cannot open a shard on the canary group without the planning job\'s map', function (): void {
    $at = PestCases::patched();
    $request = PestCases::money()->reusingCoverage(Handed::maps(Path::of('absent'), Path::of('absent')));
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: PestCases::RUN_LISTING));

    expect(new Pest($at, $shell, PestCases::canary(), new CapDirectory(), Triage::standard()->bounds())->mutate($request))->toEqual(CannotJudge::because(sprintf(
        'The gate wrote no coverage map at %s/absent/map.json.gz, and reads no runner\'s map another job wrote.',
        $at->root(),
    )));
});

it('runs each control with its file served unmutated through Pest\'s override', function (): void {
    $at = PestCases::project();
    $shell = ShellFake::answering(Ran::finished(succeeded: true, output: '')->took(Seconds::of(0.4)));
    $control = Control::of(Path::of('src/Money.php'), TestIds::of(TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds')), Seconds::of(5.0));
    $runs = new Pest($at, $shell, Patching::off(), new CapDirectory(), Triage::standard()->bounds())->controls(PestCases::money(), Controls::of($control));

    expect($runs instanceof ControlRuns ? $runs->of($control) : $runs)->toEqual(ControlRun::passed(Seconds::of(0.4)));
});
