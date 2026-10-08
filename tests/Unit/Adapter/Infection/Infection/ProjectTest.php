<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Infection\Bridges;
use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Infection\Invocation;
use NightWorksIO\MutationGate\Adapter\Infection\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Infection\StaticAnalysis;
use NightWorksIO\MutationGate\Adapter\Process\LocalProcesses;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Cli\SystemClock;
use NightWorksIO\MutationGate\Core\Analysis\AsWritten;
use NightWorksIO\MutationGate\Core\Analysis\Checkable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\NotFull;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Marker;
use NightWorksIO\MutationGate\Core\Mutant\Markers;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Runner\TighterVariables;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\GzipBomb;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\InfectionCases;
use NightWorksIO\MutationGate\Tests\Support\InfectionRun;
use NightWorksIO\MutationGate\Tests\Support\InfectionShellFake;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('records a mutant a pattern of its config ignored only where native markers are allowed', function (): void {
    $at = InfectionCases::project();
    $ignored = ['ignored' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')]];
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

    expect(InfectionCases::statuses(new Infection($at, InfectionCases::shell($at, $ignored), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: true, files: new CapDirectory())->mutate($request)))
        ->toBe([MutantStatus::IgnoredByMarker])
        ->and(new Infection($at, InfectionCases::shell($at, $ignored), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request))
        ->toBeInstanceOf(CannotJudge::class);
});

it('finds the native markers in the files asked for and in the project\'s config', function (): void {
    $at = InfectionCases::project('{"mutators": {"Plus": {"ignore": ["App\\\\Money"]}}}');
    Scratch::write($at->root(), 'src/Held.php', "<?php\n// @infection-ignore-all\n");
    $markers = new Infection($at, InfectionCases::shell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->markers(Paths::of(Path::of('src/Held.php')));
    $refused = InfectionCases::project('{"testFramework": "phpspec"}');

    expect($markers instanceof Markers ? array_map(static fn(Marker $marker): string => $marker->where(), iterator_to_array($markers, preserve_keys: false)) : [])
        ->toBe(['src/Held.php:2', 'infection.json5 mutators.Plus.ignore'])
        ->and(new Infection($refused, InfectionCases::shell($refused, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->markers(Paths::none()))
        ->toBeInstanceOf(CannotJudge::class);
});

it('is defined by its config, by any of its names, and the PHPUnit config in phpUnit.configDir or the root', function (string $config, string $directory): void {
    $at = InfectionCases::project($config);
    $definitions = new Infection($at, InfectionCases::shell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->definitions();
    $phpunit = array_map(
        static fn(string $name): string => $directory === '' ? $name : sprintf('%s/%s', $directory, $name),
        ['phpunit.xml', 'phpunit.dist.xml', 'phpunit.xml.dist'],
    );

    expect(array_map(static fn(Path $path): string => $path->value(), [...$definitions]))
        ->toBe(['infection.json5', 'infection.json', 'infection.json5.dist', 'infection.json.dist', ...$phpunit]);
})->with([
    'no config' => ['', ''],
    'a config that names no directory' => ['{"phpUnit": {}}', ''],
    'a directory of the project' => ['{"phpUnit": {"configDir": "config/"}}', 'config'],
    'a config it refuses' => ['{"testFramework": "phpspec", "phpUnit": {"configDir": "config"}}', ''],
]);

it('is defined by no PHPUnit config where phpUnit.configDir is outside the project', function (string $directory): void {
    $at = InfectionCases::project(sprintf('{"phpUnit": {"configDir": "%s"}}', $directory));
    $definitions = new Infection($at, InfectionCases::shell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->definitions();

    expect(array_map(static fn(Path $path): string => $path->value(), [...$definitions]))
        ->toBe(['infection.json5', 'infection.json', 'infection.json5.dist', 'infection.json.dist']);
})->with(['/elsewhere', '..', '../shared']);

it('names each test by the file that declares its class and its method, running nothing', function (): void {
    $at = InfectionCases::project();
    $shell = InfectionCases::shell($at, []);
    $asked = TestIds::of(
        TestId::of('Tests\\MoneyTest::adds'),
        TestId::of('Tests\\MoneyTest::adds#2'),
        TestId::of('Tests\\GoneTest::adds'),
        TestId::of('Tests\\MoneyTest'),
    );
    $adds = TestName::in(Path::of('tests/MoneyTest.php'), 'adds');

    expect(new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->names($asked, Withheld::standard()))
        ->toEqual(TestNames::none()
            ->with(TestId::of('Tests\\MoneyTest::adds'), $adds)
            ->with(TestId::of('Tests\\MoneyTest::adds#2'), TestRow::of($adds, '#2')))
        ->and($shell->commands())->toBe([]);
});

it('roots itself in a package that installs Infection, with the tests the package keeps', function (): void {
    $at = InfectionCases::project();
    Scratch::write($at->root(), 'packages/billing/vendor/bin/infection', '<?php');
    Scratch::write($at->root(), 'packages/billing/spec/LedgerTest.php', "<?php\nnamespace Tests;\nfinal class LedgerTest {}");
    Scratch::write($at->root(), 'packages/billing/tests/TallyTest.php', "<?php\nnamespace Tests;\nfinal class TallyTest {}");
    $shell = InfectionCases::shell($at, []);
    $rooted = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->rootedAt(Path::of('packages/billing'), Paths::of(Path::of('spec')));
    $asked = TestIds::of(TestId::of('Tests\\LedgerTest::books'), TestId::of('Tests\\TallyTest::counts'), TestId::of('Tests\\MoneyTest::adds'));

    expect($rooted instanceof Infection ? $rooted->names($asked, Withheld::standard()) : $rooted)
        ->toEqual(TestNames::none()->with(TestId::of('Tests\\LedgerTest::books'), TestName::in(Path::of('spec/LedgerTest.php'), 'books')))
        ->and($shell->directories())->toBe([sprintf('%s/packages/billing', $at->root())]);
});

it('cannot root itself in a directory that installs no Infection', function (): void {
    $at = InfectionCases::project();
    $shell = InfectionCases::shell($at, []);

    expect(new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->rootedAt(Path::of('packages/billing'), Paths::of(Path::of('tests'))))
        ->toEqual(CannotJudge::because('packages/billing holds no project Infection can run: Infection is not installed there.'))
        ->and($shell->directories())->toBe([]);
});

it('is built from the options the flows write, or is invalid', function (): void {
    expect(Infection::fromOptions(Configs::options('{"timeout": 30, "nativeMarkers": "allow"}'), new CapDirectory(), new LocalProcesses(new SystemClock())))->toBeInstanceOf(Infection::class)
        ->and(Infection::fromOptions(Configs::options('{"nativeMarkers": "sometimes"}'), new CapDirectory(), new LocalProcesses(new SystemClock())))
        ->toEqual(Invalid::because(Problem::at('nativeMarkers', 'expected "refuse" or "allow", got "sometimes"')));
});

it('cannot judge a run whose earlier reports or logs cannot be removed, or whose reused coverage is not there', function (): void {
    $at = InfectionCases::project();
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    new Infection($at, InfectionCases::shell($at, InfectionCases::killed($at)), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request);
    $locked = static function (string $directory, Closure $run): mixed {
        chmod($directory, 0o555);
        $answer = $run();
        chmod($directory, 0o755);

        return $answer;
    };
    $adapter = new Infection($at, InfectionCases::shell($at, InfectionCases::killed($at)), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());
    $coverage = sprintf('%s/.gate/infection/coverage', $at->root());
    $logs = sprintf('%s/.gate/infection/logs', $at->root());

    expect($locked($coverage, static fn(): mixed => $adapter->mutate($request)))->toEqual(CannotJudge::because(sprintf(
        'The gate cannot remove %s/junit.xml, so it cannot tell what this run wrote from what an earlier one did.',
        $coverage,
    )))->and($locked($logs, static fn(): mixed => $adapter->mutate($request)))->toEqual(CannotJudge::because(sprintf(
        'The gate cannot remove %s/infection.json, so it cannot tell what this run wrote from what an earlier one did.',
        $logs,
    )))->and($adapter->mutate($request->reusingCoverage(Handed::maps(Path::of('nowhere'), Path::of('nowhere')))))->toEqual(CannotJudge::because(sprintf(
        'The gate wrote no coverage map at %s/nowhere/map.json.gz, and reads no runner\'s map another job wrote.',
        $at->root(),
    )));
});

it('cannot judge a run whose coverage run wrote no report', function (): void {
    $at = InfectionCases::project();
    $silent = InfectionShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

    expect(new Infection($at, $silent, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request))
        ->toEqual(CannotJudge::because(sprintf(
            '%s/.gate/infection/coverage/coverage-xml/index.xml is not there or is not PHPUnit XML coverage, '
            . 'so the gate cannot say which tests run which line.',
            $at->root(),
        )))
        ->and(count($silent->commands()))->toBe(1);
});

it('withholds from the coverage run and every mutant\'s tests what the request withholds', function (): void {
    $at = InfectionCases::project();
    $shell = InfectionCases::shell($at, [
        'killed' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')],
    ]);
    new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate(
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'))
            ->withholding(Withheld::of('CI_JOB_TOKEN')),
    );
    new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())->coverage(
        CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'))
            ->withholding(Withheld::of('CI_JOB_TOKEN')),
    );

    expect(count($shell->commands()))->toBe(3)
        ->and(array_map(static fn(Command $command): Withheld => $command->withheld(), $shell->commands()))
        ->each->toEqual(Withheld::standard()->and(Withheld::of('CI_JOB_TOKEN')));
});

it('keeps every PHP process of a capped run to the cap, and not its coverage run, and removes the cap once done', function (): void {
    $at = InfectionCases::project();
    $shell = InfectionCases::shell($at, [
        'killed' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')],
    ]);
    new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate(
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->cappedAt(MemoryCap::standard()),
    );
    $directory = dirname(sprintf('%s/%s', MemoryScan::directoryIn($at), MemoryCap::FILE));

    expect(array_map(static fn(Command $command): array => $command->environment(), $shell->commands()))->toBe([
        ['XDEBUG_MODE' => 'coverage'],
        [
            ChildVariable::MutantFloor->value => '6.000000',
            ChildVariable::Results->value => $at->own(Invocation::SILENCED),
            MemoryCap::SCAN_DIR => MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), $directory),
        ],
    ])
        ->and(is_dir($directory))->toBeFalse();
});

it('tells a mutation run the mutators timeouts.tighter lists and their floor, which a patched Infection reads', function (): void {
    $at = InfectionCases::project();
    $shell = InfectionCases::shell($at, [
        'killed' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')],
    ]);
    $tighter = TighterSilence::of(Seconds::of(5.0), 'ArrayItemRemoval');
    new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0))->tighterFor($tighter), nativeMarkersAllowed: false, files: new CapDirectory())->mutate(
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()),
    );

    expect($shell->commands()[1]->environment())->toMatchArray(TighterVariables::of($tighter));
});

it('cannot judge a capped run whose cap cannot be written', function (): void {
    $at = InfectionCases::project();
    $shell = InfectionCases::shell($at, ['killed' => []]);
    mkdir(sprintf('%s/%s', MemoryScan::directoryIn($at), MemoryCap::FILE), recursive: true);

    expect(new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate(
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->cappedAt(MemoryCap::standard()),
    ))->toEqual(CannotJudge::because(sprintf(MemoryCap::UNWRITTEN, sprintf('%s/%s', MemoryScan::directoryIn($at), MemoryCap::FILE))));
});

it('runs a shard of the flows on the map the plan handed it, in its own layout, its kill vouched for by its control', function (): void {
    $at = InfectionCases::project();
    Scratch::write($at->root(), 'phpunit.xml', '<phpunit/>');
    $plan = Planned::of(
        Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(1.0), 'money'),
    );
    new Handoff(Directory::at($at->root()), HandedMaps::limits())->write($plan, CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->timed(TestId::of('Tests\MoneyTest::adds'), Seconds::of(0.5)), KillHistory::none(), Unplaced::map());
    $shell = InfectionCases::shell($at, InfectionCases::killed($at));
    $infection = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());

    new Running(Flows::adapters($at->root(), [], $infection), Flows::settings(), Flows::setup())
        ->run($plan, ShardId::of(1), Workspace::results());
    $file = sprintf('%s/.mutation-gate/results/1.json', $at->root());
    $result = ShardResultFile::decode((string) file_get_contents($file));
    $outcome = $result instanceof ShardResult ? $result->outcome() : $result;

    expect(InfectionCases::statuses($outcome))->toBe([MutantStatus::Killed])
        ->and(count($shell->commands()))->toBe(2)
        ->and(InfectionCases::ran($shell)[0])->toContain(sprintf('--coverage=%s/.gate/infection/coverage', $at->root()))
        ->and($shell->commands()[1]->arguments())->toContain(sprintf('--configuration=%s/.gate/infection/unmutated/control-0/phpunit.xml', $at->root()));
});

it('behaves as the port expects of a runner, but stops each mutant at its first killer and runs one per core', function (): void {
    $at = InfectionCases::project();

    expect(new Infection($at, InfectionCases::shell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->behaviour())
        ->toEqual(RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::Infection)->runningPerCore());
});

it('gives a mutant as an analyser checks it: its diff put onto the file as written, or no mutant where it does not apply or the file is gone', function (): void {
    $at = InfectionCases::project();
    Scratch::write($at->root(), 'src/Money.php', "<?php\nfunction add(){return 1+1;}\n");
    $mutant = static fn(string $file, string $diff): Mutant => Mutant::of(
        MutantId::hash(Path::of($file), 'Plus', $diff, 0),
        'Plus',
        Location::of(Path::of($file), Line::of(2), Line::of(2)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, $diff),
        MutantStatus::Survived,
        Seconds::of(0.1),
    );
    $infection = new Infection($at, InfectionCases::shell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());
    $answer = static fn(Checkable|CannotJudge $checkable): string => $checkable instanceof Checkable
        ? sprintf('%s|%s', $checkable->original()::class, $checkable->mutant()->text())
        : $checkable->why();

    expect($answer($infection->checkable($mutant('src/Money.php', "@@ @@\n-function add(){return 1+1;}\n+function add(){return 1-1;}"))))
        ->toBe(sprintf("%s|<?php\nfunction add(){return 1-1;}\n", AsWritten::class))
        ->and($answer($infection->checkable($mutant('src/Money.php', "@@ @@\n-    return 1 + 1;\n+    return 1 - 1;"))))
        ->toBe('Its diff does not apply to src/Money.php as it is now.')
        ->and($answer($infection->checkable($mutant('src/Gone.php', "@@ @@\n-a\n+b"))))
        ->toBe('The gate cannot read src/Gone.php, the file Infection mutated, to check its mutant.');
});

it('leaves static analysis to the gate where it checks the survivors itself, in every run and in a package', function (): void {
    $at = InfectionCases::project('{"staticAnalysisTool": "phpstan", "staticAnalysisToolOptions": "--level=9"}');
    $money = sprintf('%s/src/Money.php', $at->root());
    $shell = InfectionCases::shell($at, ['escaped' => [InfectionRun::entry('Minus', $money, 12, '$a - $b', '$a + $b')]]);
    $gate = new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory(), analysis: StaticAnalysis::Gate);
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    $generated = static fn(): string => (string) file_get_contents($at->own('infection.json5'));

    $first = $gate->mutate($request);
    $mutated = $generated();
    $gate->retry($request, $first instanceof MutationResult ? $first->mutants() : Mutants::none(), Seconds::of(12.0));
    $retried = $generated();
    Scratch::write($at->root(), 'packages/billing/vendor/bin/infection', '<?php');

    expect(InfectionCases::statuses($first))->toBe([MutantStatus::Survived])
        ->and($mutated)->not->toContain('staticAnalysisTool')
        ->and($retried)->not->toContain('staticAnalysisTool')
        ->and($gate->rootedAt(Path::of('packages/billing'), Paths::of(Path::of('tests'))))->toEqual(new Infection(
            $at->in(Path::of('packages/billing'), Paths::of(Path::of('tests'))),
            $shell->in(sprintf('%s/packages/billing', $at->root())),
            LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)),
            nativeMarkersAllowed: false,
            files: new CapDirectory(),
            analysis: StaticAnalysis::Gate,
        ))
        ->and(Infection::fromOptions(Configs::options('{"staticAnalysis": "gate"}'), new CapDirectory(), new LocalProcesses(new SystemClock())))
        ->not->toEqual(Infection::fromOptions(Configs::options('{}'), new CapDirectory(), new LocalProcesses(new SystemClock())));
});

it('mutates nothing, and runs nothing, where Infection runs none of the mutators a request names', function (): void {
    $at = InfectionCases::project('{"mutators": {"@default": true}}');
    $shell = InfectionCases::shell($at, []);
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators(Mutators::named('default/UnwrapHtmlspecialchars')));

    expect(new Infection($at, $shell, LimitBounds::between(Seconds::of(4.0), Seconds::of(4.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request))
        ->toEqual(MutationResult::of(Mutants::none(), 0))
        ->and($shell->commands())->toBe([]);
});

it('makes the registered mutators\' mutants through the bridges it writes as Infection\'s bootstrap, by their names, families and hints', function (): void {
    $at = InfectionCases::project('{"bootstrap": "tests/bootstrap.php"}');
    $shell = InfectionCases::shell($at, InfectionCases::killed($at, 'acme/RemoveEcho'));
    $bridges = Bridges::to(Enabled::of(MutatorSet::of(RemoveEcho::class)));
    $result = new Infection($at, $shell, LimitBounds::between(Seconds::of(4.0), Seconds::of(4.0)), nativeMarkersAllowed: false, files: new CapDirectory(), bridges: $bridges)
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $generated = json_decode((string) file_get_contents($at->own('infection.json5')), associative: true);
    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];

    expect(array_map(static fn(Mutant $mutant): array => [$mutant->mutation()->mutator(), $mutant->mutation()->family(), $mutant->mutation()->hint()], $mutants))
        ->toBe([['acme/RemoveEcho', MutatorFamily::RemovedCall, 'No test checks what is printed.']])
        ->and(is_array($generated) ? $generated['bootstrap'] : null)->toBe($at->bridges())
        ->and((string) file_get_contents($at->bridges()))
        ->toContain("return 'acme/RemoveEcho';")
        ->toContain(var_export(sprintf('%s/tests/bootstrap.php', $at->root()), return: true));
});

it('cannot judge a run whose options name a class that is not a mutator', function (): void {
    $at = InfectionCases::project();
    $why = CannotJudge::because('The infection runner cannot make mutants with stdClass, which is not a mutator.');
    $shell = InfectionCases::shell($at, InfectionCases::killed($at));

    expect(new Infection($at, $shell, LimitBounds::between(Seconds::of(4.0), Seconds::of(4.0)), nativeMarkersAllowed: false, files: new CapDirectory(), bridges: Bridges::refusing($why))
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())))->toBe($why);
});

it('keeps the coverage run and every mutant\'s tests to the suite the request names', function (): void {
    $at = InfectionCases::project();
    $shell = InfectionCases::shell($at, [
        'killed' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')],
    ]);
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'));
    new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate(
        $request->narrowedTo($request->files(), Narrowing::none()->toSuite(SuiteName::of('unit'))),
    );
    new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())->coverage(
        CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'))->inSuite(SuiteName::of('unit')),
    );
    $ran = InfectionCases::ran($shell);

    expect($ran)->toHaveCount(3)
        ->and($ran[0])->toContain('--testsuite=unit')
        ->and($ran[1])->toContain('--test-framework-extra-args=--group="holds:src/Money.php" --testsuite="unit"')
        ->and($ran[2])->toContain('--testsuite=unit');
});

it('reads the lines no test ran from the report beside the XML coverage, which leaves them out', function (): void {
    $at = InfectionCases::project();
    $map = new Infection($at, InfectionCases::missing($at, static function (): void {
    }), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.gate/planned')));

    expect($map instanceof CoverageMap ? [...$map->linesMissed(Path::of('src/Money.php'))] : $map)->toEqual([Line::of(12)])
        ->and($map instanceof CoverageMap ? [...$map->linesCovered(Path::of('src/Money.php'))] : $map)->toEqual([Line::of(11)]);
});

it('cannot judge a coverage run whose report of the lines no test ran is missing or not XML', function (Closure $spoil): void {
    $at = InfectionCases::project();
    $map = new Infection($at, InfectionCases::missing($at, $spoil), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.gate/planned')));

    expect($map)->toEqual(CannotJudge::because(sprintf(
        '%s/.gate/planned/clover.xml is not there or is not a Clover report, so the gate cannot say which lines no test ran.',
        $at->root(),
    )));
})->with([
    'missing' => [static fn(string $directory): bool => unlink(sprintf('%s/clover.xml', $directory))],
    'not XML' => [static fn(string $directory): int|false => file_put_contents(sprintf('%s/clover.xml', $directory), 'not XML')],
]);

it('writes the report of the lines no test ran only for the map, never for a mutation run\'s coverage', function (): void {
    $at = InfectionCases::project();
    $shell = InfectionCases::shell($at, InfectionCases::killed($at));
    $adapter = new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory());
    $adapter->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php')));
    $adapter->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.gate/planned')));
    $reports = array_map(
        static fn(array $arguments): bool => array_any($arguments, static fn(string $argument): bool => str_starts_with($argument, '--coverage-clover=')),
        array_values(array_filter(InfectionCases::ran($shell), static fn(array $arguments): bool => array_any(
            $arguments,
            static fn(string $argument): bool => str_starts_with($argument, '--log-junit='),
        ))),
    );

    expect($reports)->toBe([false, true]);
});

it('reads a handed-on map within this process\'s share of its memory', function (): void {
    $at = InfectionCases::project();
    $bomb = GzipBomb::padded(256 * 1_048_576);
    Scratch::write($at->root(), '.gate/bomb/map.json.gz', $bomb);
    $adapter = new Infection($at, InfectionCases::shell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());
    $limit = GzipBomb::limitAboveUse();
    $share = intdiv(ini_parse_quantity($limit), 23);
    $read = GzipBomb::readUnder($limit, static fn(): CoverageMap|CannotJudge => $adapter->coverage(CoverageRead::from(Path::of('.gate/bomb'))));

    expect($share)->toBeLessThan(300 * strlen($bomb))
        ->and(ini_get('memory_limit'))->not->toBe($limit)
        ->and($read)->toEqual(CannotJudge::because(sprintf('The coverage map inflates to more than %d bytes.', $share)));
});
