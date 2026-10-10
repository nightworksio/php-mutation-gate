<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\Clock;
use NightWorksIO\MutationGate\Adapter\Infection\Command;
use NightWorksIO\MutationGate\Adapter\Infection\CoverageXml;
use NightWorksIO\MutationGate\Adapter\Infection\Infection;
use NightWorksIO\MutationGate\Adapter\Infection\Patch;
use NightWorksIO\MutationGate\Adapter\Runtime\CapDirectory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutators;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Narrowing;
use NightWorksIO\MutationGate\Core\Runner\Platform;
use NightWorksIO\MutationGate\Core\Runner\Ran;
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
use NightWorksIO\MutationGate\Core\Verdict\Warning;
use NightWorksIO\MutationGate\Tests\Support\Described;
use NightWorksIO\MutationGate\Tests\Support\InfectionCases;
use NightWorksIO\MutationGate\Tests\Support\InfectionRun;
use NightWorksIO\MutationGate\Tests\Support\InfectionShellFake;
use NightWorksIO\MutationGate\Tests\Support\InfectionSource;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('names Infection, the versions it drives, and the PHP it runs on', function (): void {
    $at = InfectionCases::project();
    Scratch::write($at->root(), 'vendor/composer/installed.json', (string) json_encode(['packages' => [
        ['name' => 'infection/infection', 'version' => '0.35.5', 'source' => ['reference' => 'i']],
        ['name' => 'phpunit/phpunit', 'version' => '13.3.4', 'source' => ['reference' => 'p']],
        ['name' => 'phpunit/php-code-coverage', 'version' => '14.3.5', 'source' => ['reference' => 'c']],
    ]]));
    $shell = InfectionCases::shell($at, []);
    $adapter = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());
    $withheld = Withheld::of('DEPLOY_*');

    expect($adapter->identity($withheld))->toEqual(Identity::of('infection', Versions::of(
        Version::of('infection/infection', '0.35.5', 'i'),
        Version::of('phpunit/phpunit', '13.3.4', 'p'),
        Version::of('phpunit/php-code-coverage', '14.3.5', 'c'),
    ), Described::platform()->digest()))
        ->and($shell->commands())->toEqual([Command::php(...Platform::describing())->withholding($withheld)])
        ->and(new Infection(InfectionCases::project(), InfectionCases::shell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
            ->identity(Withheld::standard()))
        ->toBeInstanceOf(CannotJudge::class);
});

it('cannot say which Infection it runs where the PHP it starts does not describe itself', function (): void {
    $at = InfectionCases::project();
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
    $at = InfectionCases::project('{"staticAnalysisTool": "phpstan"}');
    Scratch::write($at->root(), 'vendor/composer/installed.json', (string) json_encode(['packages' => [
        ['name' => 'infection/infection', 'version' => '0.35.5'],
        ['name' => 'phpunit/phpunit', 'version' => '13.3.4'],
        ['name' => 'phpunit/php-code-coverage', 'version' => '14.3.5'],
        ['name' => 'phpstan/phpstan', 'version' => '2.2.0'],
    ]]));
    $identity = new Infection($at, InfectionCases::shell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->identity(Withheld::standard());
    $refused = InfectionCases::project('{"testFramework": "phpspec"}');

    expect($identity instanceof Identity ? count($identity->versions()) : 0)->toBe(4)
        ->and(new Infection($refused, InfectionCases::shell($refused, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
            ->identity(Withheld::standard()))
        ->toBeInstanceOf(CannotJudge::class);
});

it('lists the groups PHPUnit lists, and none in a project whose config it refuses', function (): void {
    $at = InfectionCases::project();
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: true, output: "Available test groups:\n - slow (1 test)\n"));
    $refused = InfectionCases::project('{"testFramework": "codeception"}');
    $untouched = InfectionCases::shell($refused, []);

    $infection = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());

    expect($infection->groups(Withheld::of('CI_JOB_TOKEN')))->toEqual(Groups::of(Group::named('slow')))
        ->and(InfectionCases::ran($shell))->toBe([[sprintf('%s/vendor/bin/phpunit', $at->root()), sprintf('--configuration=%s', $at->root()), '--list-groups', '--colors=never']])
        ->and($shell->commands()[0]->withheld())->toEqual(Withheld::standard()->and(Withheld::of('CI_JOB_TOKEN')))
        ->and(new Infection($refused, $untouched, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->groups(Withheld::standard()))
        ->toBeInstanceOf(CannotJudge::class)
        ->and($untouched->commands())->toBe([]);
});

it('runs the suite or a group under coverage into a directory and reads the map it wrote', function (): void {
    $at = InfectionCases::project();
    $shell = InfectionCases::shell($at, []);
    $map = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->coverage(CoverageRun::of(Group::named('slow'), Path::of('.gate/planned')));

    expect($map)->toEqual(CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->timed(TestId::of('Tests\MoneyTest::adds'), Seconds::of(0.5)))
        ->and(InfectionCases::ran($shell)[0])->toContain(sprintf('--coverage-xml=%s/.gate/planned/coverage-xml', $at->root()))
        ->and(InfectionCases::ran($shell)[0])->toContain('--group=slow');
});

it('reads the map another job handed on without running anything, and never a runner\'s own report', function (): void {
    $at = InfectionCases::project();
    $measured = new Infection($at, InfectionCases::shell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('.gate/planned')));
    $handed = InfectionCases::handedOn($at, '.gate/planned');
    $shell = InfectionCases::shell($at, []);
    $adapter = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());

    expect($measured)->toBeInstanceOf(CoverageMap::class)
        ->and($adapter->coverage(CoverageRead::from(Path::of('.gate/planned'))))->toEqual($handed)
        ->and($adapter->coverage(CoverageRead::from(Path::of('elsewhere'))))->toEqual(CannotJudge::because(sprintf(
            'The gate wrote no coverage map at %s/elsewhere/map.json.gz, and reads no runner\'s map another job wrote.',
            $at->root(),
        )))
        ->and($shell->commands())->toBe([]);
});

it('reads the reports a coverage run this job started itself left in a directory, running nothing', function (): void {
    $at = InfectionCases::project();
    $measured = new Infection($at, InfectionCases::shell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->coverage(CoverageRun::of(WholeSuite::tests(), Path::of('build/suite')));
    $shell = InfectionCases::shell($at, []);
    $adapter = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());

    expect($measured)->toBeInstanceOf(CoverageMap::class)
        ->and($adapter->coverage(CoverageRan::in(Path::of('build/suite'))))->toEqual($measured)
        ->and($adapter->coverage(CoverageRan::in(Path::of('build/none'))))->toBeInstanceOf(CannotJudge::class)
        ->and($shell->commands())->toBe([])
        ->and(sprintf('%s/build/none', $at->root()))->not->toBeDirectory();
});

it('times a run of no test, started as a mutant\'s run, withholding what it is told', function (): void {
    $at = InfectionCases::project();
    Scratch::write($at->root(), 'phpunit.xml', '<phpunit bootstrap="vendor/autoload.php"/>');
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: true, output: 'No tests executed!')->took(Seconds::of(1.2)));
    $withheld = Withheld::of('DEPLOY_*');

    expect(new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->startUp(Path::of('src/Money.php'), $withheld))
        ->toEqual(Seconds::of(1.2))
        ->and(array_map(static fn(Command $command): Withheld => $command->withheld(), $shell->commands()))
        ->toEqual([Withheld::standard()->and($withheld)])
        ->and(InfectionCases::ran($shell)[0][1])->toBe(sprintf('--configuration=%s/.gate/infection/start-up/phpunit.xml', $at->root()))
        ->and(is_file(sprintf('%s/.gate/infection/start-up/phpunit.xml', $at->root())))->toBeTrue();
});

it('cannot judge a run of no test that fails, with what PHPUnit said, or one over a config it refuses', function (): void {
    $at = InfectionCases::project();
    $failed = InfectionShellFake::answering(Ran::finished(succeeded: false, output: 'Fatal error'));
    $refused = InfectionCases::project('{"phpUnit": {"customPath": "vendor/bin/pest"}}');
    $untouched = InfectionCases::shell($refused, []);

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
    $at = InfectionCases::project();
    $shell = InfectionShellFake::answering(Ran::finished(succeeded: true, output: ''));

    expect(new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->startUp(Path::of('src/Money.php'), Withheld::standard()))
        ->toEqual(CannotJudge::because(sprintf('A run on Infection\'s config for a mutant needs PHPUnit\'s config, and there is none in %s.', $at->root())))
        ->and($shell->commands())->toBe([]);
});

it('cannot judge a coverage run that fails, with what PHPUnit said, or one over a config it refuses', function (): void {
    $at = InfectionCases::project();
    $failed = InfectionShellFake::answering(Ran::finished(succeeded: false, output: 'Tests: 1 failed'));
    $refused = InfectionCases::project('{"phpUnit": {"customPath": "vendor/bin/pest"}}');
    $request = CoverageRun::of(WholeSuite::tests(), Path::of('.gate/planned'));
    $untouched = InfectionCases::shell($refused, []);

    expect(new Infection($at, $failed, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->coverage($request))
        ->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nTests: 1 failed"))
        ->and(new Infection($refused, $untouched, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->coverage($request))
        ->toEqual(CannotJudge::because(
            'infection.json5 points phpUnit.customPath at vendor/bin/pest. Infection cannot run Pest tests: use the Pest runner.',
        ))
        ->and($untouched->commands())->toBe([]);
});

it('names the tests of a map whose classes some test files declare', function (): void {
    $at = InfectionCases::project();
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('Tests\HeldTest::holds'));
    $adapter = new Infection($at, InfectionCases::shell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());

    expect($adapter->testsIn(Paths::of(Path::of('tests/MoneyTest.php')), $map))
        ->toEqual(TestIds::of(TestId::of('Tests\MoneyTest::adds')));
});

it('names the files of the test classes whose tests cover a file, and none for a file nothing covers', function (): void {
    $at = InfectionCases::project();
    $map = CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(12), TestId::of('Tests\MoneyTest::adds with data set #1'));
    $adapter = new Infection($at, InfectionCases::shell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory());

    expect($adapter->judges(Path::of('src/Money.php'), $map))->toEqual(Paths::of(Path::of('tests/MoneyTest.php')))
        ->and($adapter->judges(Path::of('src/Nowhere.php'), $map))->toEqual(Paths::none());
});

it('mutates after running the tests under coverage, and reads every mutant with the limit Infection allowed it', function (): void {
    $at = InfectionCases::project('{"minMsi": 100, "mutators": {"@default": true}}');
    $shell = InfectionCases::shell($at, [
        'timeouted' => [InfectionRun::entry('Plus', sprintf('%s/src/Money.php', $at->root()), 11, '$a + $b', '$a - $b')],
    ]);
    $result = new Infection($at, $shell, LimitBounds::between(Seconds::of(4.0), Seconds::of(4.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $generated = json_decode((string) file_get_contents($at->own('infection.json5')), associative: true);
    $mutants = $result instanceof MutationResult ? iterator_to_array($result->mutants(), preserve_keys: false) : [];

    expect(InfectionCases::statuses($result))->toBe([MutantStatus::TimedOut])
        ->and($mutants[0]->limit())->toEqual(Seconds::of(4.0))
        ->and(count($shell->commands()))->toBe(2)
        ->and(InfectionCases::ran($shell)[0])->toContain(sprintf('--log-junit=%s/.gate/infection/coverage/junit.xml', $at->root()))
        ->and(InfectionCases::ran($shell)[1])->toContain(sprintf('--coverage=%s/.gate/infection/coverage', $at->root()))
        ->and(InfectionCases::ran($shell)[1])->toContain(sprintf('%s/src/Money.php', $at->root()))
        ->and(is_array($generated) ? [$generated['timeout'], array_key_exists('minMsi', $generated)] : [])->toBe([4.0, false]);
});

it('gives each mutant Infection\'s own limit and warns where Infection is not patched, and the gate\'s where it is', function (): void {
    $timedOut = static function (bool $patched): MutationResult|CannotJudge {
        $at = InfectionCases::project('{"minMsi": 100, "mutators": {"@default": true}}');

        if ($patched) {
            Patch::applyIn(InfectionSource::pristine()->into(sprintf('%s/vendor', $at->root())));
        }

        $shell = InfectionCases::shell($at, [
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
    $at = InfectionCases::project();
    $handed = InfectionCases::handedOn($at, 'planned');
    $shell = InfectionCases::shell($at, InfectionCases::killed($at));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')))
        ->narrowedTo(Paths::of(Path::of('src/Money.php')), Narrowing::none()->toMutators(Mutators::named('Plus')));
    $result = new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request);
    $own = sprintf('%s/.gate/infection/coverage', $at->root());

    expect(InfectionCases::statuses($result))->toBe([MutantStatus::Killed])
        ->and(count($shell->commands()))->toBe(1)
        ->and(InfectionCases::ran($shell)[0])->toContain(sprintf('--coverage=%s', $own))
        ->and(CoverageXml::read($at, DiskPath::of($own)))->toEqual($handed);
});

it('names the steps its time went to: the coverage it readies and reads, preparing, the mutants\' run, reading the logs', function (): void {
    $at = InfectionCases::project();
    InfectionCases::handedOn($at, 'planned');
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())
        ->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));
    $clock = new class implements Clock {
        private int $read = 0;

        public function nanoseconds(): int
        {
            return Seconds::NANOSECONDS * $this->read++;
        }
    };
    $result = new Infection($at, InfectionCases::shell($at, InfectionCases::killed($at)), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory(), clock: $clock)
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
    $at = InfectionCases::project();
    Scratch::write($at->root(), 'planned/map.json.gz', CoverageMapFile::encode(
        CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\GoneTest::adds')),
        Unplaced::map(),
    ));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));

    expect(new Infection($at, InfectionCases::shell($at, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request))
        ->toEqual(CannotJudge::because(
            'The coverage map names the test class Tests\GoneTest, and no test file declares it, so Infection cannot run its tests.',
        ));
});

it('never judges a held path with a map of the whole suite, but runs its own tests under coverage', function (): void {
    $at = InfectionCases::project();
    InfectionRun::coverage(sprintf('%s/planned', $at->root()), $at->root(), [], [], []);
    $shell = InfectionCases::shell($at, InfectionCases::killed($at));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), Filter::matching('MoneyTest'))
        ->reusingCoverage(Handed::maps(Path::of('planned'), Path::of('planned')));
    new Infection($at, $shell, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request);

    expect(InfectionCases::ran($shell)[0])->toContain('--filter=MoneyTest')
        ->and(InfectionCases::ran($shell)[1])->toContain(sprintf('--coverage=%s/.gate/infection/coverage', $at->root()))
        ->and(InfectionCases::ran($shell)[1])->toContain('--only-covering-test-cases');
});

it('never reads the logs an earlier run left', function (): void {
    $at = InfectionCases::project();
    new Infection($at, InfectionCases::shell($at, InfectionCases::killed($at)), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests()));
    $silent = InfectionCases::shell($at, [], logs: false);

    expect(new Infection($at, $silent, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())
        ->mutate(MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())))
        ->toEqual(CannotJudge::because("Infection wrote no log, so no mutant it ran has a result. Infection said:\nsaid"));
});

it('cannot judge a run stopped at its deadline, a failed coverage run, or a config it refuses', function (): void {
    $at = InfectionCases::project();
    $stopping = new InfectionShellFake(static fn(Command $command, int $before): Ran => $before === 0
        ? InfectionCases::shell($at, [])->run($command)
        : Ran::stopped('half'));
    $request = MutationRequest::of(Paths::of(Path::of('src/Money.php')), WholeSuite::tests())->within(Seconds::of(60.0));
    $refused = InfectionCases::project('{"phpUnit": {"customPath": "vendor/bin/pest"}}');

    expect(new Infection($at, $stopping, LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request))
        ->toEqual(CannotJudge::because(
            'Infection was stopped at its deadline, before it wrote its log, so no mutant of this run has a result.',
        ))
        ->and($stopping->commands()[1]->deadline())->toEqual(Seconds::of(60.0))
        ->and(new Infection($at, InfectionCases::shell($at, [], covers: false), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request))
        ->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nsaid"))
        ->and(new Infection($refused, InfectionCases::shell($refused, []), LimitBounds::between(Seconds::of(10.0), Seconds::of(10.0)), nativeMarkersAllowed: false, files: new CapDirectory())->mutate($request))
        ->toEqual(CannotJudge::because(
            'infection.json5 points phpUnit.customPath at vendor/bin/pest. Infection cannot run Pest tests: use the Pest runner.',
        ));
});
