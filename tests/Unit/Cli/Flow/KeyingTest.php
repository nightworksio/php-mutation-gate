<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Keying;
use NightWorksIO\MutationGate\Cli\Flow\Setup;
use NightWorksIO\MutationGate\Cli\Flow\Suite;
use NightWorksIO\MutationGate\Config\Baseline;
use NightWorksIO\MutationGate\Config\Option;
use NightWorksIO\MutationGate\Config\Pest;
use NightWorksIO\MutationGate\Config\Proofs;
use NightWorksIO\MutationGate\Config\Report;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Proof\Unkeyed;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Core\Runner\Versions;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestNames;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Tests\Fakes\CiPlanFake;
use NightWorksIO\MutationGate\Tests\Fakes\RunnerFake;
use NightWorksIO\MutationGate\Tests\Support\Configs;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use NightWorksIO\MutationGate\Tests\Support\StoppedClock;

afterEach(function (): void {
    Scratch::sweep();
});

/** The map every keying test reads: the canary's test covers `src/Money.php`. */
function keyingMap(): CoverageMap
{
    return CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('CanaryTest::runs'));
}

/** A runner of this name, which says the canary's test file judges every covered file. */
function keyingRunner(string $name): RunnerFake
{
    return new RunnerFake(
        Identity::of($name, Versions::of(Version::of('fake/runner', '1.0.0', 'abc')), Digest::of('php')),
        Groups::of(),
        CoverageMap::empty(),
        Mutants::none(),
        Paths::of(Path::of('tests/CanaryTest.php')),
        Paths::of(Path::of('phpunit.xml')),
        TestNames::none(),
        Paths::none(),
    );
}

/** The suite of a project whose canary test file holds this text. */
function keyingSuite(string $canary): Suite
{
    $project = Scratch::directory();
    $text = sprintf("<?php\nit('runs', fn () => %s)->group('mutation-canary');\n", $canary);
    Scratch::write($project, 'tests/CanaryTest.php', $text);
    $files = Fingerprints::of(
        Fingerprint::of(Path::of('tests/CanaryTest.php'), Digest::sha256Of($text)),
        Fingerprint::of(Path::of('src/Money.php'), Digest::sha256Of('money')),
    );
    $suite = Suite::read(Flows::trees(), $files, Directory::at($project));

    return $suite instanceof Suite ? $suite : throw new RuntimeException($suite->why());
}

/** The keying of a run. */
function keyingOf(object $port, Settings $settings, Suite $suite, Setup $setup): Keying
{
    $keying = Keying::of(Flows::adapters(Flows::project(), [], $port), $settings, $setup, $suite, keyingMap());

    return $keying instanceof Keying ? $keying : throw new RuntimeException($keying->why());
}

/** The base of a run whose CI definition, which runs the gate, holds this text. */
function keyedBase(string $definition): Digest
{
    $project = Flows::project();
    Scratch::write($project, '.github/workflows/gate.yml', $definition);
    $ci = Flows::ci()->runBy(Paths::of(Path::of('.github/workflows/gate.yml'), Path::of('.github/workflows/gone.yml')));
    $keying = Keying::of(
        Flows::adapters($project, [], keyingRunner('fake'), $ci),
        Flows::settings(),
        Flows::setup(),
        keyingSuite('1'),
        CoverageMap::empty(),
    );

    return $keying instanceof Keying ? $keying->base() : throw new RuntimeException($keying->why());
}

it('keys every unit on the one base of the run, and each by the tests that judge it', function (): void {
    $keys = keyingOf(keyingRunner('fake'), Flows::settings(), keyingSuite('1'), Flows::setup())->keysOf(Units::of(
        Unit::file(Path::of('src/Money.php')),
        Unit::file(Path::of('src/Other.php')),
        Unit::held(Path::of('src/Held.php'), Group::named('holds:src/Held.php')),
    ));

    expect($keys)->toHaveCount(3)
        ->and($keys->keyOf(Path::of('src/Money.php')))->toBeInstanceOf(Digest::class)
        ->and($keys->keyOf(Path::of('src/Other.php')))->toBeInstanceOf(Digest::class)
        ->and($keys->keyOf(Path::of('src/Held.php')))->toBeInstanceOf(Digest::class)
        ->and($keys->keyOf(Path::of('src/Money.php')))->not->toEqual($keys->keyOf(Path::of('src/Other.php')))
        ->and($keys->keyOf(Path::of('src/Held.php')))->not->toEqual($keys->keyOf(Path::of('src/Other.php')));
});

it('reads the test file that judges a unit into its key alone', function (): void {
    $units = Units::of(Unit::file(Path::of('src/Money.php')), Unit::file(Path::of('src/Other.php')));
    $before = keyingOf(keyingRunner('fake'), Flows::settings(), keyingSuite('1'), Flows::setup());
    $after = keyingOf(keyingRunner('fake'), Flows::settings(), keyingSuite('2'), Flows::setup());

    expect($after->base())->toEqual($before->base())
        ->and($after->keysOf($units)->keyOf(Path::of('src/Money.php')))
        ->not->toEqual($before->keysOf($units)->keyOf(Path::of('src/Money.php')))
        ->and($after->keysOf($units)->keyOf(Path::of('src/Other.php')))
        ->toEqual($before->keysOf($units)->keyOf(Path::of('src/Other.php')));
});

it('reads the canary group\'s test files into every key where Pest runs with the patch', function (): void {
    $patched = Flows::settings(Pest::patched());

    expect(keyingOf(keyingRunner('pest'), $patched, keyingSuite('2'), Flows::setup())->base())
        ->not->toEqual(keyingOf(keyingRunner('pest'), $patched, keyingSuite('1'), Flows::setup())->base());
});

it('reads no canary into every key where Pest runs without the patch, or another runner runs', function (
    string $name,
    Pest $patch,
): void {
    $settings = Flows::settings($patch);

    expect(keyingOf(keyingRunner($name), $settings, keyingSuite('2'), Flows::setup())->base())
        ->toEqual(keyingOf(keyingRunner($name), $settings, keyingSuite('1'), Flows::setup())->base());
})->with([
    'Pest without the patch' => ['pest', Pest::unpatched()],
    'another runner with it' => ['fake', Pest::patched()],
]);

it('reads each CI definition into every key, as it is on disk', function (): void {
    expect(keyedBase('on: push'))->not->toEqual(keyedBase('on: pull_request'));
});

it('cannot key a run whose runner cannot say what it is, or whose CI definition cannot be read', function (): void {
    $project = Flows::project();
    Scratch::write($project, '.github/workflows/gate.yml/blocked', '');
    $unnamed = new RunnerFake(
        CannotJudge::because('The runner is not installed.'),
        Groups::of(),
        CoverageMap::empty(),
        Mutants::none(),
        Paths::none(),
        Paths::none(),
        TestNames::none(),
        Paths::none(),
    );
    $ci = Flows::ci()->runBy(Paths::of(Path::of('.github/workflows/gate.yml')));

    $keyed = static fn(object $port): Keying|CannotJudge => Keying::of(
        Flows::adapters($project, [], $port),
        Flows::settings(),
        Flows::setup(),
        keyingSuite('1'),
        keyingMap(),
    );

    expect($keyed($unnamed))
        ->toEqual(CannotJudge::because('The runner is not installed.'))
        ->and($keyed($ci))
        ->toEqual(CannotJudge::because(sprintf('%s/.github/workflows/gate.yml could not be read.', $project)));
});

it('leaves out of every key the config, the baseline, proofs.ignore and every file the gate writes', function (): void {
    $settings = Flows::settings(
        Baseline::at('floors.json'),
        Proofs::ignore('docs/**', 'phpunit.xml'),
        Proofs::directory('cache/ledger'),
        Report::json('build/report.json'),
        Report::uses('console'),
    );
    $setup = new Setup(
        Path::of('mutation-gate.json'),
        Flows::setup()->gate,
        Digest::of('installed'),
        new StoppedClock(Configs::NOW),
    );
    $exceptions = Keying::exceptions(Flows::adapters(Flows::project()), $settings, $setup);

    expect($exceptions->leaveOut(Path::of('mutation-gate.json')))->toBeTrue()
        ->and($exceptions->leaveOut(Path::of('floors.json')))->toBeTrue()
        ->and($exceptions->leaveOut(Path::of('docs/index.md')))->toBeTrue()
        ->and($exceptions->leaveOut(Path::of('cache/ledger/refs/heads/main/ledger.json.gz')))->toBeTrue()
        ->and($exceptions->leaveOut(Path::of('build/report.json')))->toBeTrue()
        ->and($exceptions->leaveOut(Path::of('.mutation-gate/plan.json')))->toBeTrue()
        ->and($exceptions->leaveOut(Path::of('tests/Pest.php')))->toBeFalse()
        ->and($exceptions->leaveOut(Path::of('src/Money.php')))->toBeFalse();
});

it('leaves out nothing for a store with no path of its own, and never what defines the runner', function (): void {
    $settings = Flows::settings(Proofs::ignore('tests/**'), Proofs::uses(CiPlanFake::class, Option::of('path', 5)));
    $exceptions = Keying::exceptions(Flows::adapters(Flows::project()), $settings, Flows::setup());

    expect($exceptions->leaveOut(Path::of('tests/Pest.php')))->toBeFalse()
        ->and($exceptions->leaveOut(Path::of('tests/MoneyTest.php')))->toBeTrue()
        ->and($exceptions->leaveOut(Path::of('mutation-gate.json')))->toBeFalse();
});

/**
 * The key of `src/Money.php` in a project whose PHPUnit config keeps its
 * tests beside the source in `src`, where `src/Clock.php`, which no test
 * names, holds this text.
 */
function keyingColocated(string $clock): Digest|Unkeyed
{
    $project = Scratch::directory();
    $files = [
        'phpunit.xml' => <<<'XML'
            <phpunit>
                <testsuites>
                    <testsuite name="Unit"><directory suffix="Test.php">src</directory></testsuite>
                </testsuites>
            </phpunit>
            XML,
        'src/Money.php' => "<?php\nfinal class Money {}\n",
        'src/MoneyTest.php' => "<?php\nit('adds', fn () => new Money());\n",
        'src/Clock.php' => $clock,
    ];
    $fingerprints = Fingerprints::none();

    foreach ($files as $path => $text) {
        Scratch::write($project, $path, $text);
        $fingerprints = $fingerprints->with(Fingerprint::of(Path::of($path), Digest::sha256Of($text)));
    }

    $suite = Suite::read(Flows::trees(), $fingerprints, Directory::at($project));
    $runner = new RunnerFake(
        Identity::of('fake', Versions::of(Version::of('fake/runner', '1.0.0', 'abc')), Digest::of('php')),
        Groups::of(),
        CoverageMap::empty(),
        Mutants::none(),
        Paths::of(Path::of('src/MoneyTest.php')),
        Paths::of(Path::of('phpunit.xml')),
        TestNames::none(),
        Paths::none(),
    );
    $map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(2), TestId::of('MoneyTest::adds'));
    $keying = $suite instanceof Suite
        ? Keying::of(Flows::adapters($project, [], $runner), Flows::settings(), Flows::setup(), $suite, $map)
        : $suite;

    return $keying instanceof Keying
        ? $keying->keysOf(Units::of(Unit::file(Path::of('src/Money.php'))))->keyOf(Path::of('src/Money.php'))
        : throw new RuntimeException($keying->why());
}

it('keys every file a tree holds as source, though the PHPUnit config keeps its tests beside it', function (): void {
    expect(keyingColocated("<?php\nfinal class Clock { public function now(): int { return 1; } }\n"))
        ->not->toEqual(keyingColocated("<?php\nfinal class Clock { public function now(): int { return 2; } }\n"));
});
