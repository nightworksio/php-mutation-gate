<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Infection\Bridges;
use NightWorksIO\MutationGate\Adapter\Infection\Clock;
use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\CoverageXml;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Infection\Invocation;
use NightWorksIO\MutationGate\Adapter\Infection\MemoryScan;
use NightWorksIO\MutationGate\Adapter\Infection\Patch;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
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
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethod;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
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
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Plan\ShardResult;
use NightWorksIO\MutationGate\Core\Plan\ShardResultFile;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Reproducible;
use NightWorksIO\MutationGate\Core\Runner\Reproduction;
use NightWorksIO\MutationGate\Core\Runner\RunnerBehaviour;
use NightWorksIO\MutationGate\Core\Runner\Unmade;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\SuiteName;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Test\TestRow;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Mutator\Engine\Enabled;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Described;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\GzipBomb;
use NightWorksIO\MutationGate\Tests\Support\HandedMaps;
use NightWorksIO\MutationGate\Tests\Support\InfectionRun;
use NightWorksIO\MutationGate\Tests\Support\InfectionShellFake;
use NightWorksIO\MutationGate\Tests\Support\InfectionSource;
use NightWorksIO\MutationGate\Tests\Support\Mutators\RemoveEcho;
use NightWorksIO\MutationGate\Tests\Support\Planned;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project in a scratch directory, with src/Money.php and its test, and the gate's directory in .gate. */
function infectionProject(string $config = ''): Project
{
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'src/Money.php', '<?php');
    Scratch::write($root, 'tests/MoneyTest.php', "<?php\nnamespace Tests;\nfinal class MoneyTest {}");

    if ($config !== '') {
        Scratch::write($root, 'infection.json5', $config);
    }

    return Project::at(Root::of($root), Paths::of(Path::of('tests')), Path::of('.gate'));
}

/**
 * A shell that answers as PHP, PHPUnit and Infection would: PHP describes
 * itself, a coverage run writes MoneyTest covering line 11 of src/Money.php,
 * and a run of Infection writes these lists of its logs.
 *
 * @param array<string, list<array<string, mixed>>> $lists
 */
function infectionShell(Project $at, array $lists, bool $covers = true, bool $logs = true): InfectionShellFake
{
    $running = infectionRunning($at, $lists, $covers, $logs);

    return new InfectionShellFake(static fn(Command $command): Ran => in_array('-r', $command->arguments(), strict: true)
        ? Ran::finished(succeeded: true, output: Described::output())
        : $running($command));
}

/**
 * @param  array<string, list<array<string, mixed>>> $lists
 * @return Closure(Command): Ran
 */
function infectionRunning(Project $at, array $lists, bool $covers, bool $logs): Closure
{
    return static function (Command $command) use ($at, $lists, $covers, $logs): Ran {
        foreach ($command->arguments() as $argument) {
            if ($covers && str_starts_with($argument, '--log-junit=')) {
                InfectionRun::coverage(
                    dirname(mb_substr($argument, mb_strlen('--log-junit='))),
                    $at->root(),
                    ['src/Money.php' => [11 => ['Tests\MoneyTest::adds']]],
                    ['Tests\MoneyTest' => 0.5],
                    ['Tests\MoneyTest::adds' => 0.5],
                );
            }

            if ($logs && str_starts_with($argument, '--skip-initial-tests')) {
                InfectionRun::log($at->own('logs/infection.json'), $lists);
                InfectionRun::text($at->own('logs/infection.log'), []);
            }
        }

        return Ran::finished(succeeded: $covers, output: 'said');
    };
}

/** @return array<string, list<array<string, mixed>>> */
function infectionKilled(Project $at, string $mutator = 'Plus'): array
{
    return ['killed' => [InfectionRun::entry($mutator, sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')]];
}

/** @return list<list<string>> each command's arguments, without the PHP that runs it */
function infectionRan(InfectionShellFake $shell): array
{
    return array_map(static fn(Command $command): array => array_slice($command->arguments(), 1), $shell->commands());
}

/** @return list<MutantStatus|CannotJudge> */
function infectionStatuses(MutationResult|Mutants|CannotJudge $result): array
{
    $mutants = $result instanceof MutationResult ? $result->mutants() : $result;

    return $mutants instanceof Mutants
        ? array_map(static fn(Mutant $mutant): MutantStatus => $mutant->status(), iterator_to_array($mutants, preserve_keys: false))
        : [$mutants];
}

it('names Infection, the versions it drives, and the PHP it runs on', function (): void {
    $at = infectionProject();
    Scratch::write($at->root(), 'vendor/composer/installed.json', (string) json_encode(['packages' => [
        ['name' => 'infection/infection', 'version' => '0.35.5', 'source' => ['reference' => 'i']],
        ['name' => 'phpunit/phpunit', 'version' => '13.3.4', 'source' => ['reference' => 'p']],
        ['name' => 'phpunit/php-code-coverage', 'version' => '14.3.5', 'source' => ['reference' => 'c']],
    ]]));
    $shell = infectionShell($at, []);
    $adapter = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());
    $withheld = Withheld::of('DEPLOY_*');

    expect($adapter->identity($withheld))->toEqual(Identity::of('infection', Versions::of(
        Version::of('infection/infection', '0.35.5', 'i'),
        Version::of('phpunit/phpunit', '13.3.4', 'p'),
        Version::of('phpunit/php-code-coverage', '14.3.5', 'c'),
    ), Described::platform()->digest()))
        ->and($shell->commands())->toEqual([Command::php(...Platform::describing())->withholding($withheld)])
        ->and(new Infection(infectionProject(), infectionShell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
            ->identity(Withheld::standard()))
        ->toBeInstanceOf(CannotJudge::class);
});

it('cannot say which Infection it runs where the PHP it starts does not describe itself', function (): void {
    $at = infectionProject();
    Scratch::write($at->root(), 'vendor/composer/installed.json', (string) json_encode(['packages' => [
        ['name' => 'infection/infection', 'version' => '0.35.5'],
        ['name' => 'phpunit/phpunit', 'version' => '13.3.4'],
        ['name' => 'phpunit/php-code-coverage', 'version' => '14.3.5'],
    ]]));
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: false, output: 'Segmentation fault'));

    expect(new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->identity(Withheld::standard()))
        ->toEqual(Platform::ofRunner('Segmentation fault'));
});

it('names the static analysis tool the project has kill mutants among what it drives, and nothing for a config it refuses', function (): void {
    $at = infectionProject('{"staticAnalysisTool": "phpstan"}');
    Scratch::write($at->root(), 'vendor/composer/installed.json', (string) json_encode(['packages' => [
        ['name' => 'infection/infection', 'version' => '0.35.5'],
        ['name' => 'phpunit/phpunit', 'version' => '13.3.4'],
        ['name' => 'phpunit/php-code-coverage', 'version' => '14.3.5'],
        ['name' => 'phpstan/phpstan', 'version' => '2.2.0'],
    ]]));
    $identity = new Infection($at, infectionShell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->identity(Withheld::standard());
    $refused = infectionProject('{"testFramework": "phpspec"}');

    expect($identity instanceof Identity ? count($identity->versions()) : 0)->toBe(4)
        ->and(new Infection($refused, infectionShell($refused, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
            ->identity(Withheld::standard()))
        ->toBeInstanceOf(CannotJudge::class);
});

it('lists the groups PHPUnit lists, and none in a project whose config it refuses', function (): void {
    $at = infectionProject();
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: true, output: "Available test groups:\n - slow (1 test)\n"));
    $refused = infectionProject('{"testFramework": "codeception"}');
    $untouched = infectionShell($refused, []);

    $infection = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());

    expect($infection->groups(Withheld::of('CI_JOB_TOKEN')))->toEqual(Groups::of(Group::named('slow')))
        ->and(infectionRan($shell))->toBe([[sprintf('%s/vendor/bin/phpunit', $at->root()), sprintf('--configuration=%s', $at->root()), '--list-groups', '--colors=never']])
        ->and($shell->commands()[0]->withheld())->toEqual(Withheld::standard()->and(Withheld::of('CI_JOB_TOKEN')))
        ->and(new Infection($refused, $untouched, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->groups(Withheld::standard()))
        ->toBeInstanceOf(CannotJudge::class)
        ->and($untouched->commands())->toBe([]);
});

it('runs the suite or a group under coverage into a directory and reads the map it wrote', function (): void {
    $at = infectionProject();
    $shell = infectionShell($at, []);
    $map = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->coverage(CoverageRun::of(Group::named('slow'), Path::of('.gate/planned')));

    expect($map)->toEqual(CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->timed(TestId::of('Tests\MoneyTest::adds'), Seconds::of(0.5)))
        ->and(infectionRan($shell)[0])->toContain(sprintf('--coverage-xml=%s/.gate/planned/coverage-xml', $at->root()))
        ->and(infectionRan($shell)[0])->toContain('--group=slow');
});

/** The gate's own map another job handed on in a directory of a project: Money's line 11, which MoneyTest ran. */
function infectionHandedOn(Project $at, string $directory): CoverageMap
{
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->timed(TestId::of('Tests\MoneyTest::adds'), Seconds::of(0.5))
        ->executing(Path::of('src/Money.php'), ExecutedMethod::of('add', 9, 12));
    Scratch::write($at->root(), sprintf('%s/map.json.gz', $directory), CoverageMapFile::encode($map, Unplaced::map()));

    return $map;
}

it('reads the map another job handed on without running anything, and never a runner\'s own report', function (): void {
    $at = infectionProject();
    $measured = new Infection($at, infectionShell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.gate/planned')));
    $handed = infectionHandedOn($at, '.gate/planned');
    $shell = infectionShell($at, []);
    $adapter = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());

    expect($measured)->toBeInstanceOf(CoverageMap::class)
        ->and($adapter->coverage(CoverageRead::from(Path::of('.gate/planned'))))->toEqual($handed)
        ->and($adapter->coverage(CoverageRead::from(Path::of('elsewhere'))))->toEqual(CannotJudge::because(sprintf(
            'The gate wrote no coverage map at %s/elsewhere/map.json.gz, and reads no runner\'s map another job wrote.',
            $at->root(),
        )))
        ->and($shell->commands())->toBe([]);
});

it('times a run of no test, started as a mutant\'s run, withholding what it is told', function (): void {
    $at = infectionProject();
    Scratch::write($at->root(), 'phpunit.xml', '<phpunit bootstrap="vendor/autoload.php"/>');
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: true, output: 'No tests executed!')->took(Seconds::of(1.2)));
    $withheld = Withheld::of('DEPLOY_*');

    expect(new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->startUp(Path::of('src/Money.php'), $withheld))
        ->toEqual(Seconds::of(1.2))
        ->and(array_map(static fn(Command $command): Withheld => $command->withheld(), $shell->commands()))
        ->toEqual([Withheld::standard()->and($withheld)])
        ->and(infectionRan($shell)[0][1])->toBe(sprintf('--configuration=%s/.gate/infection/start-up/phpunit.xml', $at->root()))
        ->and(is_file(sprintf('%s/.gate/infection/start-up/phpunit.xml', $at->root())))->toBeTrue();
});

it('cannot judge a run of no test that fails, with what PHPUnit said, or one over a config it refuses', function (): void {
    $at = infectionProject();
    $failed = InfectionShellFake::answering(Ran::finished(succeeded: false, output: 'Fatal error'));
    $refused = infectionProject('{"phpUnit": {"customPath": "vendor/bin/pest"}}');
    $untouched = infectionShell($refused, []);

    Scratch::write($at->root(), 'phpunit.xml', '<phpunit/>');

    expect(new Infection($at, $failed, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->startUp(Path::of('src/Money.php'), Withheld::standard()))
        ->toEqual(CannotJudge::because("PHPUnit's run of no test, timing a mutant's start-up, failed. It said:\nFatal error"))
        ->and(new Infection($refused, $untouched, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->startUp(Path::of('src/Money.php'), Withheld::standard()))
        ->toEqual(CannotJudge::because(
            'infection.json5 points phpUnit.customPath at vendor/bin/pest. Infection cannot run Pest tests: use the Pest runner.',
        ))
        ->and($untouched->commands())->toBe([]);
});

it('cannot time a run of no test in a project with no PHPUnit config, running nothing', function (): void {
    $at = infectionProject();
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->startUp(Path::of('src/Money.php'), Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf('The run of no test needs PHPUnit\'s config, and there is none in %s.', $at->root())))
        ->and($shell->commands())->toBe([]);
});

it('cannot judge a coverage run that fails, with what PHPUnit said, or one over a config it refuses', function (): void {
    $at = infectionProject();
    $failed = InfectionShellFake::answering(Ran::finished(succeeded: false, output: 'Tests: 1 failed'));
    $refused = infectionProject('{"phpUnit": {"customPath": "vendor/bin/pest"}}');
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.gate/planned'));
    $untouched = infectionShell($refused, []);

    expect(new Infection($at, $failed, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->coverage($request))
        ->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nTests: 1 failed"))
        ->and(new Infection($refused, $untouched, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->coverage($request))
        ->toEqual(CannotJudge::because(
            'infection.json5 points phpUnit.customPath at vendor/bin/pest. Infection cannot run Pest tests: use the Pest runner.',
        ))
        ->and($untouched->commands())->toBe([]);
});

it('names the tests of a map whose classes some test files declare', function (): void {
    $at = infectionProject();
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('Tests\HeldTest::holds'));
    $adapter = new Infection($at, infectionShell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());

    expect($adapter->testsIn(Paths::of(Path::of('tests/MoneyTest.php')), $map))
        ->toEqual(TestIds::of(TestId::of('Tests\MoneyTest::adds')));
});

it('names the files of the test classes whose tests cover a file, and none for a file nothing covers', function (): void {
    $at = infectionProject();
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('Tests\MoneyTest::adds with data set #1'));
    $adapter = new Infection($at, infectionShell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());

    expect($adapter->judges(Path::of('src/Money.php'), $map))->toEqual(Paths::of(Path::of('tests/MoneyTest.php')))
        ->and($adapter->judges(Path::of('src/Nowhere.php'), $map))->toEqual(Paths::none());
});

it('mutates after running the tests under coverage, and reads every mutant with the limit Infection allowed it', function (): void {
    $at = infectionProject('{"minMsi": 100, "mutators": {"@default": true}}');
    $shell = infectionShell($at, [
        'timeouted' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')],
    ]);
    $result = new Infection($at, $shell, LimitBounds::between(Seconds::of(4.0), Seconds::of(4.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $generated = json_decode((string) file_get_contents($at->own('infection.json5')), associative: true);
    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];

    expect(infectionStatuses($result))->toBe([MutantStatus::TimedOut])
        ->and($mutants[0]->limit())->toEqual(Seconds::of(4.0))
        ->and(count($shell->commands()))->toBe(2)
        ->and(infectionRan($shell)[0])->toContain(sprintf('--log-junit=%s/.gate/infection/coverage/junit.xml', $at->root()))
        ->and(infectionRan($shell)[1])->toContain(sprintf('--coverage=%s/.gate/infection/coverage', $at->root()))
        ->and(infectionRan($shell)[1])->toContain(sprintf('%s/src/Money.php', $at->root()))
        ->and(is_array($generated) ? [$generated['timeout'], array_key_exists('minMsi', $generated)] : [])->toBe([4.0, false]);
});

it('gives each mutant Infection\'s own limit and warns where Infection is not patched, and the gate\'s where it is', function (): void {
    $timedOut = static function (bool $patched): MutationResult|CannotJudge {
        $at = infectionProject('{"minMsi": 100, "mutators": {"@default": true}}');

        if ($patched) {
            Patch::applyIn(InfectionSource::pristine()->into(sprintf('%s/vendor', $at->root())));
        }

        $shell = infectionShell($at, [
            'timeouted' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')],
        ]);

        return new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(300.0)), nativeMarkersAllowed: false, files: new CapDirectory())
            ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    };
    $limits = static fn(MutationResult|CannotJudge $result): array => $result instanceof MutationResult
        ? array_map(static fn(Mutant $mutant): mixed => $mutant->limit(), iterator_to_array($result->mutants(), preserve_keys: false))
        : [];
    $warned = static fn(MutationResult|CannotJudge $result): array => $result instanceof MutationResult
        ? array_map(static fn(Warning $warning): string => $warning->text(), [...$result->warnings()])
        : [];
    $unpatched = $timedOut(patched: false);
    $patched = $timedOut(patched: true);

    expect($limits($unpatched))->toEqual([Seconds::of(7.5)])
        ->and($warned($unpatched))->toBe(['Unpatched, Infection gave each mutant its own limit, with no floor. Run mutation-gate infection:patch.'])
        ->and($limits($patched))->toEqual([Seconds::of(10.0)])
        ->and($warned($patched))->toBe([]);
});

it('writes the map another job handed on in its own layout for a run judged by the whole suite, and runs no suite', function (): void {
    $at = infectionProject();
    $handed = infectionHandedOn($at, 'planned');
    $shell = infectionShell($at, infectionKilled($at));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')))
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators(Mutators::named('Plus')));
    $result = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request);
    $own = sprintf('%s/.gate/infection/coverage', $at->root());

    expect(infectionStatuses($result))->toBe([MutantStatus::Killed])
        ->and(count($shell->commands()))->toBe(1)
        ->and(infectionRan($shell)[0])->toContain(sprintf('--coverage=%s', $own))
        ->and(CoverageXml::read($at, DiskPath::of($own)))->toEqual($handed);
});

it('names the steps its time went to: the coverage it readies and reads, preparing, the mutants\' run, reading the logs', function (): void {
    $at = infectionProject();
    infectionHandedOn($at, 'planned');
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));
    $clock = new class implements Clock {
        private int $read = 0;

        public function nanoseconds(): int
        {
            return Seconds::NANOSECONDS * $this->read++;
        }
    };
    $result = new Infection($at, infectionShell($at, infectionKilled($at)), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory(), clock: $clock)
        ->mutate($request);

    expect($result instanceof MutationResult ? array_map(
        static fn(StepTime $step): array => [$step->step(), $step->since()->seconds(), $step->took()->seconds(), $step->count()],
        [...$result->steps()],
    ) : $result)->toBe([
        [Step::Coverage, 1.0, 1.0, 1],
        [Step::Coverage, 3.0, 1.0, 1],
        [Step::Preparing, 5.0, 1.0, 1],
        [Step::Mutation, 7.0, 1.0, 1],
        [Step::Reading, 9.0, 1.0, 1],
    ]);
});

it('cannot judge a handed-on map whose test class no test file declares', function (): void {
    $at = infectionProject();
    Scratch::write($at->root(), 'planned/map.json.gz', CoverageMapFile::encode(
        CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\GoneTest::adds')),
        Unplaced::map(),
    ));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    expect(new Infection($at, infectionShell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request))
        ->toEqual(CannotJudge::because(
            'The coverage map names the test class Tests\GoneTest, and no test file declares it, so Infection cannot run its tests.',
        ));
});

it('never judges a held path with a map of the whole suite, but runs its own tests under coverage', function (): void {
    $at = infectionProject();
    InfectionRun::coverage(sprintf('%s/planned', $at->root()), $at->root(), [], [], []);
    $shell = infectionShell($at, infectionKilled($at));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Filter::matching('MoneyTest'))
        ->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));
    new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request);

    expect(infectionRan($shell)[0])->toContain('--filter=MoneyTest')
        ->and(infectionRan($shell)[1])->toContain(sprintf('--coverage=%s/.gate/infection/coverage', $at->root()))
        ->and(infectionRan($shell)[1])->toContain('--only-covering-test-cases');
});

it('never reads the logs an earlier run left', function (): void {
    $at = infectionProject();
    new Infection($at, infectionShell($at, infectionKilled($at)), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $silent = infectionShell($at, [], logs: false);

    expect(new Infection($at, $silent, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())))
        ->toEqual(CannotJudge::because("Infection wrote no log, so no mutant it ran has a result. Infection said:\nsaid"));
});

it('cannot judge a run stopped at its deadline, a failed coverage run, or a config it refuses', function (): void {
    $at = infectionProject();
    $stopping = new InfectionShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? infectionShell($at, [])->run($command)
        : Ran::stopped('half'));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->within(Seconds::of(60.0));
    $refused = infectionProject('{"phpUnit": {"customPath": "vendor/bin/pest"}}');

    expect(new Infection($at, $stopping, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request))
        ->toEqual(CannotJudge::because(
            'Infection was stopped at its deadline, before it wrote its log, so no mutant of this run has a result.',
        ))
        ->and($stopping->commands()[1]->deadline())->toEqual(Seconds::of(60.0))
        ->and(new Infection($at, infectionShell($at, [], covers: false), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request))
        ->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nsaid"))
        ->and(new Infection($refused, infectionShell($refused, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request))
        ->toEqual(CannotJudge::because(
            'infection.json5 points phpUnit.customPath at vendor/bin/pest. Infection cannot run Pest tests: use the Pest runner.',
        ));
});

it('runs each mutant again by its unit\'s tests at the higher cap and with no deadline, whatever decided its limit', function (): void {
    $at = infectionProject();
    $money = sprintf('%s/src/Money.php', $at->root());
    $first = infectionShell($at, [
        'timeouted' => [
            InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b'),
            InfectionRun::entry('Plus', $money, 40, '$c + $d', '$c - $d'),
        ],
        'escaped' => [InfectionRun::entry('Minus', $money, 12, '$a - $b', '$a + $b')],
    ]);
    $result = new Infection($at, $first, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $mutants = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $again = infectionShell($at, [
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

    expect(infectionStatuses($mutants))->toBe([MutantStatus::TimedOut, MutantStatus::Survived, MutantStatus::TimedOut])
        ->and(infectionStatuses($retried))->toBe([MutantStatus::Killed, MutantStatus::Survived, MutantStatus::Unjudged])
        ->and(count($again->commands()))->toBe(3)
        ->and(infectionRan($again)[0])->toContain('--group=holds:src/Money.php')
        ->and(infectionRan($again)[1])->toContain('--test-framework-extra-args=--group="holds:src/Money.php"')
        ->and($again->commands()[1]->deadline())->toEqual(Unlimited::time())
        ->and(array_map(static fn(Command $command): Withheld => $command->withheld(), $again->commands()))
        ->each->toEqual(Withheld::standard()->and(Withheld::of('DEPLOY_*')))
        ->and(is_array($generated) ? [$generated['timeout'], $generated['mutators']] : [])->toBe([12.0, ['Minus' => true]]);
});

it('runs mutants again on the map the planning job handed the invocation, and runs no suite for them', function (): void {
    $at = infectionProject();
    $money = sprintf('%s/src/Money.php', $at->root());
    $first = new Infection($at, infectionShell($at, [
        'timeouted' => [InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b')],
    ]), LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    infectionHandedOn($at, 'planned');
    $again = infectionShell($at, infectionKilled($at));
    $invocation = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    $retried = new Infection($at, $again, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->retry($invocation, $first instanceof MutationResult ? $first->mutants() : Mutants::none(), Seconds::of(12.0));

    expect(infectionStatuses($retried))->toBe([MutantStatus::Killed])
        ->and(count($again->commands()))->toBe(1)
        ->and(infectionRan($again)[0])->toContain(sprintf('--coverage=%s/.gate/infection/coverage', $at->root()));
});

it('runs a retry on the coverage its mutation run left, and collects it again for other tests and for each mutation run', function (): void {
    $at = infectionProject();
    $money = sprintf('%s/src/Money.php', $at->root());
    $shell = infectionShell($at, ['timeouted' => [InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b')]]);
    $infection = new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory());
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'));
    $coverageRuns = static fn(): int => count(array_filter(
        infectionRan($shell),
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
    $at = infectionProject();
    $money = sprintf('%s/src/Money.php', $at->root());
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->within(Seconds::of(100.0));
    $result = new Infection($at, infectionShell($at, [
        'timeouted' => [InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b')],
        'escaped' => [InfectionRun::entry('Minus', $money, 12, '$a - $b', '$a + $b')],
    ]), LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request);
    $again = infectionShell($at, infectionKilled($at));
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
    $at = infectionProject();
    $result = new Infection($at, infectionShell($at, [
        'timeouted' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')],
    ]), LimitBounds::between(Seconds::of(4.0), Seconds::of(4.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $mutants = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $idle = infectionShell($at, []);
    $refused = infectionProject('{"testFramework": "phpspec"}');

    $retried = static fn(Project $project, InfectionShellFake $shell, Mutants $asked): Mutants|CannotJudge => new Infection(
        $project,
        $shell,
        LimitBounds::between(Seconds::of(4.0), Seconds::of(4.0)),
        nativeMarkersAllowed: false,
        files: new CapDirectory(),
    )->retry(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()), $asked, Seconds::of(8.0));

    expect($retried($at, $idle, Mutants::none()))->toEqual(Mutants::none())
        ->and($idle->commands())->toBe([])
        ->and($retried($at, infectionShell($at, [], covers: false), $mutants))
        ->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nsaid"))
        ->and($retried($at, infectionShell($at, [], logs: false), $mutants))
        ->toEqual(CannotJudge::because("Infection wrote no log, so no mutant it ran has a result. Infection said:\nsaid"))
        ->and($retried($refused, infectionShell($refused, []), $mutants))
        ->toBeInstanceOf(CannotJudge::class);
});

it('reproduces a mutant alone with only its mutator, by its unit\'s tests at the limit, whatever its first limit was', function (): void {
    $at = infectionProject();
    $money = sprintf('%s/src/Money.php', $at->root());
    $result = new Infection($at, infectionShell($at, ['timeouted' => [InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b')]]), LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $timedOut = null;

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $timedOut = $mutant;
    }

    $again = infectionShell($at, infectionKilled($at));
    $reproduced = $timedOut instanceof Mutant ? new Infection($at, $again, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->reproduce(Reproducible::of($timedOut), MutationRequest::of(Paths::none(), Group::named('holds:src/Money.php'))->withholding(Withheld::of('DEPLOY_*')), Seconds::of(12.0)) : null;
    $generated = json_decode((string) file_get_contents($at->own('infection.json5')), associative: true);

    expect($timedOut instanceof Mutant ? $timedOut->status() : $timedOut)->toBe(MutantStatus::TimedOut)
        ->and($reproduced instanceof Reproduction && $reproduced->mutant() instanceof Mutant ? [$reproduced->mutant()->status(), $reproduced->printed()] : $reproduced)
        ->toBe([MutantStatus::Killed, 'said'])
        ->and(count($again->commands()))->toBe(2)
        ->and(infectionRan($again)[0])->toContain('--group=holds:src/Money.php')
        ->and(infectionRan($again)[1])->toContain('--test-framework-extra-args=--group="holds:src/Money.php"')
        ->and(array_map(static fn(Command $command): Withheld => $command->withheld(), $again->commands()))
        ->each->toEqual(Withheld::standard()->and(Withheld::of('DEPLOY_*')))
        ->and(is_array($generated) ? [$generated['timeout'], $generated['mutators']] : [])->toBe([12.0, ['Plus' => true]]);
});

it('says Infection made no mutant with the id where it no longer makes it, and cannot judge where its config, coverage or run fails', function (): void {
    $at = infectionProject();
    $gone = Mutant::of(
        MutantId::hash(Path::of('src/Money.php'), 'Minus', '-gone', 0),
        '',
        Location::of(Path::of('src/Money.php'), Line::of(12), Line::of(12)),
        Mutation::of('Minus', MutatorFamily::Arithmetic, '-gone'),
        MutantStatus::Survived,
        Unmeasured::duration(),
    );
    $refused = infectionProject('{"testFramework": "phpspec"}');
    $reproduced = static fn(Project $project, InfectionShellFake $shell): Reproduction|CannotJudge => new Infection(
        $project,
        $shell,
        LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)),
        nativeMarkersAllowed: false,
        files: new CapDirectory(),
    )->reproduce(Reproducible::of($gone), MutationRequest::of(Paths::none(), WholeSuite::tests())->withholding(Withheld::standard()), Seconds::of(6.0));
    $unjudged = $reproduced($at, infectionShell($at, infectionKilled($at)));

    expect($unjudged instanceof Reproduction ? $unjudged->mutant() : $unjudged)
        ->toEqual(Unmade::because(Reason::that('Run again, Infection made no mutant with this id.')))
        ->and($reproduced($at, infectionShell($at, [], covers: false)))
        ->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nsaid"))
        ->and($reproduced($at, infectionShell($at, [], logs: false)))
        ->toEqual(CannotJudge::because("Infection wrote no log, so no mutant it ran has a result. Infection said:\nsaid"))
        ->and($reproduced($refused, infectionShell($refused, [])))
        ->toBeInstanceOf(CannotJudge::class);
});

it('records a mutant a pattern of its config ignored only where native markers are allowed', function (): void {
    $at = infectionProject();
    $ignored = ['ignored' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')]];
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

    expect(infectionStatuses(new Infection($at, infectionShell($at, $ignored), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: true, files: new CapDirectory())->mutate($request)))
        ->toBe([MutantStatus::IgnoredByMarker])
        ->and(new Infection($at, infectionShell($at, $ignored), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request))
        ->toBeInstanceOf(CannotJudge::class);
});

it('finds the native markers in the files asked for and in the project\'s config', function (): void {
    $at = infectionProject('{"mutators": {"Plus": {"ignore": ["App\\\\Money"]}}}');
    Scratch::write($at->root(), 'src/Held.php', "<?php\n// @infection-ignore-all\n");
    $markers = new Infection($at, infectionShell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->markers(Paths::of(Path::of('src/Held.php')));
    $refused = infectionProject('{"testFramework": "phpspec"}');

    expect($markers instanceof Markers ? array_map(static fn(Marker $marker): string => $marker->where(), iterator_to_array($markers, preserve_keys: false)) : [])
        ->toBe(['src/Held.php:2', 'infection.json5 mutators.Plus.ignore'])
        ->and(new Infection($refused, infectionShell($refused, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->markers(Paths::none()))
        ->toBeInstanceOf(CannotJudge::class);
});

it('is defined by its config, by any of its names, and the PHPUnit config in phpUnit.configDir or the root', function (string $config, string $directory): void {
    $at = infectionProject($config);
    $definitions = new Infection($at, infectionShell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->definitions();
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
    $at = infectionProject(sprintf('{"phpUnit": {"configDir": "%s"}}', $directory));
    $definitions = new Infection($at, infectionShell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->definitions();

    expect(array_map(static fn(Path $path): string => $path->value(), [...$definitions]))
        ->toBe(['infection.json5', 'infection.json', 'infection.json5.dist', 'infection.json.dist']);
})->with(['/elsewhere', '..', '../shared']);

it('names each test by the file that declares its class and its method, running nothing', function (): void {
    $at = infectionProject();
    $shell = infectionShell($at, []);
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
    $at = infectionProject();
    Scratch::write($at->root(), 'packages/billing/vendor/bin/infection', '<?php');
    Scratch::write($at->root(), 'packages/billing/spec/LedgerTest.php', "<?php\nnamespace Tests;\nfinal class LedgerTest {}");
    Scratch::write($at->root(), 'packages/billing/tests/TallyTest.php', "<?php\nnamespace Tests;\nfinal class TallyTest {}");
    $shell = infectionShell($at, []);
    $rooted = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->rootedAt(Path::of('packages/billing'), Paths::of(Path::of('spec')));
    $asked = TestIds::of(TestId::of('Tests\\LedgerTest::books'), TestId::of('Tests\\TallyTest::counts'), TestId::of('Tests\\MoneyTest::adds'));

    expect($rooted instanceof Infection ? $rooted->names($asked, Withheld::standard()) : $rooted)
        ->toEqual(TestNames::none()->with(TestId::of('Tests\\LedgerTest::books'), TestName::in(Path::of('spec/LedgerTest.php'), 'books')))
        ->and($shell->directories())->toBe([sprintf('%s/packages/billing', $at->root())]);
});

it('cannot root itself in a directory that installs no Infection', function (): void {
    $at = infectionProject();
    $shell = infectionShell($at, []);

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
    $at = infectionProject();
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    new Infection($at, infectionShell($at, infectionKilled($at)), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request);
    $locked = static function (string $directory, Closure $run): mixed {
        chmod($directory, 0o555);
        $answer = $run();
        chmod($directory, 0o755);

        return $answer;
    };
    $adapter = new Infection($at, infectionShell($at, infectionKilled($at)), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());
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
    $at = infectionProject();
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
    $at = infectionProject();
    $shell = infectionShell($at, [
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
    $at = infectionProject();
    $shell = infectionShell($at, [
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

it('cannot judge a capped run whose cap cannot be written', function (): void {
    $at = infectionProject();
    $shell = infectionShell($at, ['killed' => []]);
    mkdir(sprintf('%s/%s', MemoryScan::directoryIn($at), MemoryCap::FILE), recursive: true);

    expect(new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate(
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->cappedAt(MemoryCap::standard()),
    ))->toEqual(CannotJudge::because(sprintf(MemoryCap::UNWRITTEN, sprintf('%s/%s', MemoryScan::directoryIn($at), MemoryCap::FILE))));
});

it('runs a shard of the flows on the map the plan handed it, in its own layout', function (): void {
    $at = infectionProject();
    $plan = Planned::of(
        Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(1.0), 'money'),
    );
    new Handoff(Directory::at($at->root()), HandedMaps::limits())->write($plan, CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->timed(TestId::of('Tests\MoneyTest::adds'), Seconds::of(0.5)), KillHistory::none(), Unplaced::map());
    $shell = infectionShell($at, infectionKilled($at));
    $infection = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());

    new Running(Flows::adapters($at->root(), [], $infection), Flows::settings(), Flows::setup())
        ->run($plan, ShardId::of(1), Workspace::results());
    $file = sprintf('%s/.mutation-gate/results/1.json', $at->root());
    $result = ShardResultFile::decode((string) file_get_contents($file));
    $outcome = $result instanceof ShardResult ? $result->outcome() : $result;

    expect(infectionStatuses($outcome))->toBe([MutantStatus::Killed])
        ->and(count($shell->commands()))->toBe(1)
        ->and(infectionRan($shell)[0])->toContain(sprintf('--coverage=%s/.gate/infection/coverage', $at->root()));
});

it('behaves as the port expects of a runner, but stops each mutant at its first killer and runs one per core', function (): void {
    $at = infectionProject();

    expect(new Infection($at, infectionShell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->behaviour())
        ->toEqual(RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::Infection)->runningPerCore());
});

it('gives a mutant as an analyser checks it: its diff put onto the file as written, or no mutant where it does not apply or the file is gone', function (): void {
    $at = infectionProject();
    Scratch::write($at->root(), 'src/Money.php', "<?php\nfunction add(){return 1+1;}\n");
    $mutant = static fn(string $file, string $diff): Mutant => Mutant::of(
        MutantId::hash(Path::of($file), 'Plus', $diff, 0),
        'Plus',
        Location::of(Path::of($file), Line::of(2), Line::of(2)),
        Mutation::of('Plus', MutatorFamily::Arithmetic, $diff),
        MutantStatus::Survived,
        Seconds::of(0.1),
    );
    $infection = new Infection($at, infectionShell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());
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
    $at = infectionProject('{"staticAnalysisTool": "phpstan", "staticAnalysisToolOptions": "--level=9"}');
    $money = sprintf('%s/src/Money.php', $at->root());
    $shell = infectionShell($at, ['escaped' => [InfectionRun::entry('Minus', $money, 12, '$a - $b', '$a + $b')]]);
    $gate = new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory(), analysis: StaticAnalysis::Gate);
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    $generated = static fn(): string => (string) file_get_contents($at->own('infection.json5'));

    $first = $gate->mutate($request);
    $mutated = $generated();
    $gate->retry($request, $first instanceof MutationResult ? $first->mutants() : Mutants::none(), Seconds::of(12.0));
    $retried = $generated();
    Scratch::write($at->root(), 'packages/billing/vendor/bin/infection', '<?php');

    expect(infectionStatuses($first))->toBe([MutantStatus::Survived])
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
    $at = infectionProject('{"mutators": {"@default": true}}');
    $shell = infectionShell($at, []);
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators(Mutators::named('default/UnwrapHtmlspecialchars')));

    expect(new Infection($at, $shell, LimitBounds::between(Seconds::of(4.0), Seconds::of(4.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request))
        ->toEqual(MutationResult::of(Mutants::none(), 0))
        ->and($shell->commands())->toBe([]);
});

it('makes the registered mutators\' mutants through the bridges it writes as Infection\'s bootstrap, by their names, families and hints', function (): void {
    $at = infectionProject('{"bootstrap": "tests/bootstrap.php"}');
    $shell = infectionShell($at, infectionKilled($at, 'acme/RemoveEcho'));
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
    $at = infectionProject();
    $why = CannotJudge::because('The infection runner cannot make mutants with stdClass, which is not a mutator.');
    $shell = infectionShell($at, infectionKilled($at));

    expect(new Infection($at, $shell, LimitBounds::between(Seconds::of(4.0), Seconds::of(4.0)), nativeMarkersAllowed: false, files: new CapDirectory(), bridges: Bridges::refusing($why))
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())))->toBe($why);
});

it('keeps the coverage run and every mutant\'s tests to the suite the request names', function (): void {
    $at = infectionProject();
    $shell = infectionShell($at, [
        'killed' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')],
    ]);
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'));
    new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate(
        $request->narrowedTo($request->files(), Narrowing::none()->toSuite(SuiteName::of('unit'))),
    );
    new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory())->coverage(
        CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'))->inSuite(SuiteName::of('unit')),
    );
    $ran = infectionRan($shell);

    expect($ran)->toHaveCount(3)
        ->and($ran[0])->toContain('--testsuite=unit')
        ->and($ran[1])->toContain('--test-framework-extra-args=--group="holds:src/Money.php" --testsuite="unit"')
        ->and($ran[2])->toContain('--testsuite=unit');
});

/** A shell whose coverage run writes Money's line 11, which MoneyTest ran, and its line 12, which no test ran, and then does this to the directory. */
function infectionMissing(Project $at, Closure $then): InfectionShellFake
{
    return new InfectionShellFake(static function (Command $command) use ($at, $then): Ran {
        foreach ($command->arguments() as $argument) {
            if (str_starts_with($argument, '--log-junit=')) {
                $directory = dirname(mb_substr($argument, mb_strlen('--log-junit=')));
                InfectionRun::coverage(
                    $directory,
                    $at->root(),
                    ['src/Money.php' => [11 => ['Tests\MoneyTest::adds'], 12 => []]],
                    ['Tests\MoneyTest' => 0.5],
                    ['Tests\MoneyTest::adds' => 0.5],
                );
                $then($directory);
            }
        }

        return Ran::finished(succeeded: true, output: 'said');
    });
}

it('reads the lines no test ran from the report beside the XML coverage, which leaves them out', function (): void {
    $at = infectionProject();
    $map = new Infection($at, infectionMissing($at, static function (): void {
    }), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.gate/planned')));

    expect($map instanceof CoverageMap ? [...$map->linesMissed(Path::of('src/Money.php'))] : $map)->toEqual([Line::of(12)])
        ->and($map instanceof CoverageMap ? [...$map->linesCovered(Path::of('src/Money.php'))] : $map)->toEqual([Line::of(11)]);
});

it('cannot judge a coverage run whose report of the lines no test ran is missing or not XML', function (Closure $spoil): void {
    $at = infectionProject();
    $map = new Infection($at, infectionMissing($at, $spoil), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
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
    $at = infectionProject();
    $shell = infectionShell($at, infectionKilled($at));
    $adapter = new Infection($at, $shell, LimitBounds::between(Seconds::of(6.0), Seconds::of(6.0)), nativeMarkersAllowed: false, files: new CapDirectory());
    $adapter->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php')));
    $adapter->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.gate/planned')));
    $reports = array_map(
        static fn(array $arguments): bool => array_any($arguments, static fn(string $argument): bool => str_starts_with($argument, '--coverage-clover=')),
        array_values(array_filter(infectionRan($shell), static fn(array $arguments): bool => array_any(
            $arguments,
            static fn(string $argument): bool => str_starts_with($argument, '--log-junit='),
        ))),
    );

    expect($reports)->toBe([false, true]);
});

it('reads a handed-on map within this process\'s share of its memory', function (): void {
    $at = infectionProject();
    $bomb = GzipBomb::padded(256 * 1_048_576);
    Scratch::write($at->root(), '.gate/bomb/map.json.gz', $bomb);
    $adapter = new Infection($at, infectionShell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());
    $limit = GzipBomb::limitAboveUse();
    $share = intdiv(ini_parse_quantity($limit), 23);
    $read = GzipBomb::readUnder($limit, static fn(): CoverageMap|CannotJudge => $adapter->coverage(CoverageRead::from(Path::of('.gate/bomb'))));

    expect($share)->toBeLessThan(300 * strlen($bomb))
        ->and(ini_get('memory_limit'))->not->toBe($limit)
        ->and($read)->toEqual(CannotJudge::because(sprintf('The coverage map inflates to more than %d bytes.', $share)));
});
