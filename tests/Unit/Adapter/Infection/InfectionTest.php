<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\CoverageXml;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Adapter\Infection\Ran;
use NightWorksIO\MutationGate\Cli\Flow\Handoff;
use NightWorksIO\MutationGate\Cli\Flow\Running;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Problem;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethod;
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
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Platform;
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
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Described;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\InfectionRun;
use NightWorksIO\MutationGate\Tests\Support\InfectionShellFake;
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
    $adapter = new Infection($at, $shell, Seconds::of(10.0), nativeMarkersAllowed: false);
    $withheld = Withheld::of('DEPLOY_*');

    expect($adapter->identity($withheld))->toEqual(Identity::of('infection', Versions::of(
        Version::of('infection/infection', '0.35.5', 'i'),
        Version::of('phpunit/phpunit', '13.3.4', 'p'),
        Version::of('phpunit/php-code-coverage', '14.3.5', 'c'),
    ), Described::platform()->digest()))
        ->and($shell->commands())->toEqual([Command::php(...Platform::describing())->withholding($withheld)])
        ->and(new Infection(infectionProject(), infectionShell($at, []), Seconds::of(10.0), nativeMarkersAllowed: false)
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

    expect(new Infection($at, $shell, Seconds::of(10.0), nativeMarkersAllowed: false)->identity(Withheld::standard()))
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
    $identity = new Infection($at, infectionShell($at, []), Seconds::of(10.0), nativeMarkersAllowed: false)
        ->identity(Withheld::standard());
    $refused = infectionProject('{"testFramework": "phpspec"}');

    expect($identity instanceof Identity ? count($identity->versions()) : 0)->toBe(4)
        ->and(new Infection($refused, infectionShell($refused, []), Seconds::of(10.0), nativeMarkersAllowed: false)
            ->identity(Withheld::standard()))
        ->toBeInstanceOf(CannotJudge::class);
});

it('lists the groups PHPUnit lists, and none in a project whose config it refuses', function (): void {
    $at = infectionProject();
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: true, output: "Available test groups:\n - slow (1 test)\n"));
    $refused = infectionProject('{"testFramework": "codeception"}');
    $untouched = infectionShell($refused, []);

    $infection = new Infection($at, $shell, Seconds::of(10.0), nativeMarkersAllowed: false);

    expect($infection->groups(Withheld::of('CI_JOB_TOKEN')))->toEqual(Groups::of(Group::named('slow')))
        ->and(infectionRan($shell))->toBe([[sprintf('%s/vendor/bin/phpunit', $at->root()), sprintf('--configuration=%s', $at->root()), '--list-groups', '--colors=never']])
        ->and($shell->commands()[0]->withheld())->toEqual(Withheld::standard()->and(Withheld::of('CI_JOB_TOKEN')))
        ->and(new Infection($refused, $untouched, Seconds::of(10.0), nativeMarkersAllowed: false)->groups(Withheld::standard()))
        ->toBeInstanceOf(CannotJudge::class)
        ->and($untouched->commands())->toBe([]);
});

it('runs the suite or a group under coverage into a directory and reads the map it wrote', function (): void {
    $at = infectionProject();
    $shell = infectionShell($at, []);
    $map = new Infection($at, $shell, Seconds::of(10.0), nativeMarkersAllowed: false)
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
    Scratch::write($at->root(), sprintf('%s/map.json.gz', $directory), CoverageMapFile::encode($map));

    return $map;
}

it('reads the map another job handed on without running anything, and never a runner\'s own report', function (): void {
    $at = infectionProject();
    $measured = new Infection($at, infectionShell($at, []), Seconds::of(10.0), nativeMarkersAllowed: false)
        ->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.gate/planned')));
    $handed = infectionHandedOn($at, '.gate/planned');
    $shell = infectionShell($at, []);
    $adapter = new Infection($at, $shell, Seconds::of(10.0), nativeMarkersAllowed: false);

    expect($measured)->toBeInstanceOf(CoverageMap::class)
        ->and($adapter->coverage(CoverageRead::from(Path::of('.gate/planned'))))->toEqual($handed)
        ->and($adapter->coverage(CoverageRead::from(Path::of('elsewhere'))))->toEqual(CannotJudge::because(sprintf(
            'The gate wrote no coverage map at %s/elsewhere/map.json.gz, and reads no runner\'s map another job wrote.',
            $at->root(),
        )))
        ->and($shell->commands())->toBe([]);
});

it('cannot judge a coverage run that fails, with what PHPUnit said, or one over a config it refuses', function (): void {
    $at = infectionProject();
    $failed = InfectionShellFake::answering(Ran::finished(succeeded: false, output: 'Tests: 1 failed'));
    $refused = infectionProject('{"phpUnit": {"customPath": "vendor/bin/pest"}}');
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.gate/planned'));
    $untouched = infectionShell($refused, []);

    expect(new Infection($at, $failed, Seconds::of(10.0), nativeMarkersAllowed: false)->coverage($request))
        ->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nTests: 1 failed"))
        ->and(new Infection($refused, $untouched, Seconds::of(10.0), nativeMarkersAllowed: false)->coverage($request))
        ->toEqual(CannotJudge::because(
            'infection.json5 points phpUnit.customPath at vendor/bin/pest. Infection cannot run Pest tests: use the Pest runner.',
        ))
        ->and($untouched->commands())->toBe([]);
});

it('names the files of the test classes whose tests cover a file, and none for a file nothing covers', function (): void {
    $at = infectionProject();
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('Tests\MoneyTest::adds with data set #1'));
    $adapter = new Infection($at, infectionShell($at, []), Seconds::of(10.0), nativeMarkersAllowed: false);

    expect($adapter->judges(Path::of('src/Money.php'), $map))->toEqual(Paths::of(Path::of('tests/MoneyTest.php')))
        ->and($adapter->judges(Path::of('src/Nowhere.php'), $map))->toEqual(Paths::none());
});

it('mutates after running the tests under coverage, and reads every mutant with the limit Infection allowed it', function (): void {
    $at = infectionProject('{"minMsi": 100, "mutators": {"@default": true}}');
    $shell = infectionShell($at, [
        'timeouted' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')],
    ]);
    $result = new Infection($at, $shell, Seconds::of(4.0), nativeMarkersAllowed: false)
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

it('writes the map another job handed on in its own layout for a run judged by the whole suite, and runs no suite', function (): void {
    $at = infectionProject();
    $handed = infectionHandedOn($at, 'planned');
    $shell = infectionShell($at, infectionKilled($at));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->reusingCoverage(Path::of('planned'))
        ->onlyMutators(Mutators::named('Plus'));
    $result = new Infection($at, $shell, Seconds::of(10.0), nativeMarkersAllowed: false)->mutate($request);
    $own = sprintf('%s/.gate/infection/coverage', $at->root());

    expect(infectionStatuses($result))->toBe([MutantStatus::Killed])
        ->and(count($shell->commands()))->toBe(1)
        ->and(infectionRan($shell)[0])->toContain(sprintf('--coverage=%s', $own))
        ->and(CoverageXml::read($at, DiskPath::of($own)))->toEqual($handed);
});

it('cannot judge a handed-on map whose test class no test file declares', function (): void {
    $at = infectionProject();
    Scratch::write($at->root(), 'planned/map.json.gz', CoverageMapFile::encode(
        CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\GoneTest::adds')),
    ));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->reusingCoverage(Path::of('planned'));

    expect(new Infection($at, infectionShell($at, []), Seconds::of(10.0), nativeMarkersAllowed: false)->mutate($request))
        ->toEqual(CannotJudge::because(
            'The coverage map names the test class Tests\GoneTest, and no test file declares it, so Infection cannot run its tests.',
        ));
});

it('never judges a held path with a map of the whole suite, but runs its own tests under coverage', function (): void {
    $at = infectionProject();
    InfectionRun::coverage(sprintf('%s/planned', $at->root()), $at->root(), [], [], []);
    $shell = infectionShell($at, infectionKilled($at));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Filter::matching('MoneyTest'))
        ->reusingCoverage(Path::of('planned'));
    new Infection($at, $shell, Seconds::of(10.0), nativeMarkersAllowed: false)->mutate($request);

    expect(infectionRan($shell)[0])->toContain('--filter=MoneyTest')
        ->and(infectionRan($shell)[1])->toContain(sprintf('--coverage=%s/.gate/infection/coverage', $at->root()))
        ->and(infectionRan($shell)[1])->toContain('--only-covering-test-cases');
});

it('never reads the logs an earlier run left', function (): void {
    $at = infectionProject();
    new Infection($at, infectionShell($at, infectionKilled($at)), Seconds::of(10.0), nativeMarkersAllowed: false)
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $silent = infectionShell($at, [], logs: false);

    expect(new Infection($at, $silent, Seconds::of(10.0), nativeMarkersAllowed: false)
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

    expect(new Infection($at, $stopping, Seconds::of(10.0), nativeMarkersAllowed: false)->mutate($request))
        ->toEqual(CannotJudge::because(
            'Infection was stopped at its deadline, before it wrote its log, so no mutant of this run has a result.',
        ))
        ->and($stopping->commands()[1]->deadline())->toEqual(Seconds::of(60.0))
        ->and(new Infection($at, infectionShell($at, [], covers: false), Seconds::of(10.0), nativeMarkersAllowed: false)->mutate($request))
        ->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nsaid"))
        ->and(new Infection($refused, infectionShell($refused, []), Seconds::of(10.0), nativeMarkersAllowed: false)->mutate($request))
        ->toEqual(CannotJudge::because(
            'infection.json5 points phpUnit.customPath at vendor/bin/pest. Infection cannot run Pest tests: use the Pest runner.',
        ));
});

it('runs each mutant again by its unit\'s tests at the higher cap and with no deadline, but a timeout the formula decided', function (): void {
    $at = infectionProject();
    $money = sprintf('%s/src/Money.php', $at->root());
    $first = infectionShell($at, [
        'timeouted' => [
            InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b'),
            InfectionRun::entry('Plus', $money, 40, '$c + $d', '$c - $d'),
        ],
        'escaped' => [InfectionRun::entry('Minus', $money, 12, '$a - $b', '$a + $b')],
    ]);
    $result = new Infection($at, $first, Seconds::of(6.0), nativeMarkersAllowed: false)
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $mutants = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $again = infectionShell($at, [
        'killed' => [InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b')],
        'escaped' => [InfectionRun::entry('Minus', $money, 12, '$a - $b', '$a + $b')],
    ]);
    $retried = new Infection($at, $again, Seconds::of(6.0), nativeMarkersAllowed: false)
        ->retry($mutants, Seconds::of(12.0), Group::named('holds:src/Money.php'), Withheld::of('DEPLOY_*'));
    $generated = json_decode((string) file_get_contents($at->own('infection.json5')), associative: true);

    expect(infectionStatuses($mutants))->toBe([MutantStatus::TimedOut, MutantStatus::Survived, MutantStatus::TimedOut])
        ->and(infectionStatuses($retried))->toBe([MutantStatus::Killed, MutantStatus::Survived, MutantStatus::TimedOut])
        ->and(count($again->commands()))->toBe(3)
        ->and(infectionRan($again)[0])->toContain('--group=holds:src/Money.php')
        ->and(infectionRan($again)[1])->toContain('--test-framework-extra-args=--group="holds:src/Money.php"')
        ->and($again->commands()[1]->deadline())->toEqual(Unlimited::time())
        ->and(array_map(static fn(Command $command): Withheld => $command->withheld(), $again->commands()))
        ->each->toEqual(Withheld::standard()->and(Withheld::of('DEPLOY_*')))
        ->and(is_array($generated) ? [$generated['timeout'], $generated['mutators']] : [])->toBe([12.0, ['Minus' => true]]);
});

it('runs nothing again where the formula decided every timeout, and cannot judge a retry whose runs fail', function (): void {
    $at = infectionProject();
    $result = new Infection($at, infectionShell($at, [
        'timeouted' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')],
    ]), Seconds::of(4.0), nativeMarkersAllowed: false)->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $mutants = $result instanceof MutationResult ? $result->mutants() : Mutants::none();
    $idle = infectionShell($at, []);
    $refused = infectionProject('{"testFramework": "phpspec"}');

    $retried = static fn(Project $project, InfectionShellFake $shell, float $cap): Mutants|CannotJudge => new Infection(
        $project,
        $shell,
        Seconds::of($cap),
        nativeMarkersAllowed: false,
    )->retry($mutants, Seconds::of($cap * 2), WholeSuite::tests(), Withheld::standard());

    expect($retried($at, $idle, 10.0))->toEqual($mutants)
        ->and($idle->commands())->toBe([])
        ->and($retried($at, infectionShell($at, [], covers: false), 4.0))
        ->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nsaid"))
        ->and($retried($at, infectionShell($at, [], logs: false), 4.0))
        ->toEqual(CannotJudge::because("Infection wrote no log, so no mutant it ran has a result. Infection said:\nsaid"))
        ->and($retried($refused, infectionShell($refused, []), 4.0))
        ->toBeInstanceOf(CannotJudge::class);
});

it('reproduces a mutant alone with only its mutator, by its unit\'s tests at the limit, whatever its first limit was', function (): void {
    $at = infectionProject();
    $money = sprintf('%s/src/Money.php', $at->root());
    $result = new Infection($at, infectionShell($at, ['timeouted' => [InfectionRun::entry('Plus', $money, 11, '$a + $b', '$a - $b')]]), Seconds::of(6.0), nativeMarkersAllowed: false)
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $timedOut = null;

    foreach ($result instanceof MutationResult ? $result->mutants() : Mutants::none() as $mutant) {
        $timedOut = $mutant;
    }

    $again = infectionShell($at, infectionKilled($at));
    $reproduced = $timedOut instanceof Mutant ? new Infection($at, $again, Seconds::of(6.0), nativeMarkersAllowed: false)
        ->reproduce(Reproducible::of($timedOut), Group::named('holds:src/Money.php'), Seconds::of(12.0), Withheld::of('DEPLOY_*')) : null;
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
        Seconds::of(6.0),
        nativeMarkersAllowed: false,
    )->reproduce(Reproducible::of($gone), WholeSuite::tests(), Seconds::of(6.0), Withheld::standard());
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

    expect(infectionStatuses(new Infection($at, infectionShell($at, $ignored), Seconds::of(10.0), nativeMarkersAllowed: true)->mutate($request)))
        ->toBe([MutantStatus::IgnoredByMarker])
        ->and(new Infection($at, infectionShell($at, $ignored), Seconds::of(10.0), nativeMarkersAllowed: false)->mutate($request))
        ->toBeInstanceOf(CannotJudge::class);
});

it('finds the native markers in the files asked for and in the project\'s config', function (): void {
    $at = infectionProject('{"mutators": {"Plus": {"ignore": ["App\\\\Money"]}}}');
    Scratch::write($at->root(), 'src/Held.php', "<?php\n// @infection-ignore-all\n");
    $markers = new Infection($at, infectionShell($at, []), Seconds::of(10.0), nativeMarkersAllowed: false)->markers(Paths::of(Path::of('src/Held.php')));
    $refused = infectionProject('{"testFramework": "phpspec"}');

    expect($markers instanceof Markers ? array_map(static fn(Marker $marker): string => $marker->where(), iterator_to_array($markers, preserve_keys: false)) : [])
        ->toBe(['src/Held.php:2', 'infection.json5 mutators.Plus.ignore'])
        ->and(new Infection($refused, infectionShell($refused, []), Seconds::of(10.0), nativeMarkersAllowed: false)->markers(Paths::none()))
        ->toBeInstanceOf(CannotJudge::class);
});

it('is defined by its config, by any of its names, and the PHPUnit config in phpUnit.configDir or the root', function (string $config, string $directory): void {
    $at = infectionProject($config);
    $definitions = new Infection($at, infectionShell($at, []), Seconds::of(10.0), nativeMarkersAllowed: false)->definitions();
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
    $definitions = new Infection($at, infectionShell($at, []), Seconds::of(10.0), nativeMarkersAllowed: false)->definitions();

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

    expect(new Infection($at, $shell, Seconds::of(10.0), nativeMarkersAllowed: false)->names($asked, Withheld::standard()))
        ->toEqual(TestNames::none()
            ->with(TestId::of('Tests\\MoneyTest::adds'), $adds)
            ->with(TestId::of('Tests\\MoneyTest::adds#2'), TestRow::of($adds, '#2')))
        ->and($shell->commands())->toBe([]);
});

it('roots itself in a package that installs Infection, with the package\'s own tests', function (): void {
    $at = infectionProject();
    Scratch::write($at->root(), 'packages/billing/vendor/bin/infection', '<?php');
    Scratch::write($at->root(), 'packages/billing/tests/LedgerTest.php', "<?php\nnamespace Tests;\nfinal class LedgerTest {}");
    $shell = infectionShell($at, []);
    $rooted = new Infection($at, $shell, Seconds::of(10.0), nativeMarkersAllowed: false)->rootedAt(Path::of('packages/billing'));
    $asked = TestIds::of(TestId::of('Tests\\LedgerTest::books'), TestId::of('Tests\\MoneyTest::adds'));

    expect($rooted instanceof Infection ? $rooted->names($asked, Withheld::standard()) : $rooted)
        ->toEqual(TestNames::none()->with(TestId::of('Tests\\LedgerTest::books'), TestName::in(Path::of('tests/LedgerTest.php'), 'books')))
        ->and($shell->directories())->toBe([sprintf('%s/packages/billing', $at->root())]);
});

it('cannot root itself in a directory that installs no Infection', function (): void {
    $at = infectionProject();
    $shell = infectionShell($at, []);

    expect(new Infection($at, $shell, Seconds::of(10.0), nativeMarkersAllowed: false)->rootedAt(Path::of('packages/billing')))
        ->toEqual(CannotJudge::because('packages/billing holds no project Infection can run: Infection is not installed there.'))
        ->and($shell->directories())->toBe([]);
});

it('is built from the options the flows write, or is invalid', function (): void {
    expect(Infection::fromOptions(Configs::options('{"timeout": 30, "nativeMarkers": "allow"}')))->toBeInstanceOf(Infection::class)
        ->and(Infection::fromOptions(Configs::options('{"nativeMarkers": "sometimes"}')))
        ->toEqual(Invalid::because(Problem::at('nativeMarkers', 'expected "refuse" or "allow", got "sometimes"')));
});

it('cannot judge a run whose earlier reports or logs cannot be removed, or whose reused coverage is not there', function (): void {
    $at = infectionProject();
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());
    new Infection($at, infectionShell($at, infectionKilled($at)), Seconds::of(10.0), nativeMarkersAllowed: false)->mutate($request);
    $locked = static function (string $directory, Closure $run): mixed {
        chmod($directory, 0o555);
        $answer = $run();
        chmod($directory, 0o755);

        return $answer;
    };
    $adapter = new Infection($at, infectionShell($at, infectionKilled($at)), Seconds::of(10.0), nativeMarkersAllowed: false);
    $coverage = sprintf('%s/.gate/infection/coverage', $at->root());
    $logs = sprintf('%s/.gate/infection/logs', $at->root());

    expect($locked($coverage, static fn(): mixed => $adapter->mutate($request)))->toEqual(CannotJudge::because(sprintf(
        'The gate cannot remove %s/junit.xml, so it cannot tell what this run wrote from what an earlier one did.',
        $coverage,
    )))->and($locked($logs, static fn(): mixed => $adapter->mutate($request)))->toEqual(CannotJudge::because(sprintf(
        'The gate cannot remove %s/infection.json, so it cannot tell what this run wrote from what an earlier one did.',
        $logs,
    )))->and($adapter->mutate($request->reusingCoverage(Path::of('nowhere'))))->toEqual(CannotJudge::because(sprintf(
        'The gate wrote no coverage map at %s/nowhere/map.json.gz, and reads no runner\'s map another job wrote.',
        $at->root(),
    )));
});

it('cannot judge a run whose coverage run wrote no report', function (): void {
    $at = infectionProject();
    $silent = InfectionShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests());

    expect(new Infection($at, $silent, Seconds::of(10.0), nativeMarkersAllowed: false)->mutate($request))
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
    new Infection($at, $shell, Seconds::of(6.0), nativeMarkersAllowed: false)->mutate(
        MutationRequest::of(Paths::of(Path::of('src/Money.php')), Group::named('holds:src/Money.php'))
            ->withholding(Withheld::of('CI_JOB_TOKEN')),
    );
    new Infection($at, $shell, Seconds::of(6.0), nativeMarkersAllowed: false)->coverage(
        CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'))
            ->withholding(Withheld::of('CI_JOB_TOKEN')),
    );

    expect(count($shell->commands()))->toBe(3)
        ->and(array_map(static fn(Command $command): Withheld => $command->withheld(), $shell->commands()))
        ->each->toEqual(Withheld::standard()->and(Withheld::of('CI_JOB_TOKEN')));
});

it('runs a shard of the flows on the map the plan handed it, in its own layout', function (): void {
    $at = infectionProject();
    $plan = Planned::of(
        Shard::of(ShardId::of(1), Package::at(Path::root()), Units::of(Planned::money()), Seconds::of(1.0), 'money'),
    );
    new Handoff(Directory::at($at->root()))->write($plan, CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->timed(TestId::of('Tests\MoneyTest::adds'), Seconds::of(0.5)), KillHistory::none());
    $shell = infectionShell($at, infectionKilled($at));
    $infection = new Infection($at, $shell, Seconds::of(10.0), nativeMarkersAllowed: false);

    new Running(Flows::adapters($at->root(), [], $infection), Flows::settings(), Flows::setup())
        ->run($plan, ShardId::of(1), Workspace::results());
    $file = sprintf('%s/.mutation-gate/results/1.json', $at->root());
    $result = ShardResultFile::decode((string) file_get_contents($file));
    $outcome = $result instanceof ShardResult ? $result->outcome() : $result;

    expect(infectionStatuses($outcome))->toBe([MutantStatus::Killed])
        ->and(count($shell->commands()))->toBe(1)
        ->and(infectionRan($shell)[0])->toContain(sprintf('--coverage=%s/.gate/infection/coverage', $at->root()));
});

it('behaves as the port expects of a runner, but stops each mutant at its first killer', function (): void {
    $at = infectionProject();

    expect(new Infection($at, infectionShell($at, []), Seconds::of(10.0), nativeMarkersAllowed: false)->behaviour())
        ->toEqual(RunnerBehaviour::standard()->stoppingAtFirstKiller(NotFull::Infection));
});
