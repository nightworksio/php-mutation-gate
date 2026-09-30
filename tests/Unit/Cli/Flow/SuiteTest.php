<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Flow\Suite;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Hold\Holder;
use NightWorksIO\MutationGate\Core\Hold\Holding;
use NightWorksIO\MutationGate\Core\Hold\Holdings;
use NightWorksIO\MutationGate\Core\Proof\Key\Role;
use NightWorksIO\MutationGate\Core\Proof\Key\TestFile;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Tests\Support\Flows;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** Each of these files of a project, fingerprinted as they are. */
$listed = static function (string ...$paths): Fingerprints {
    $fingerprints = Fingerprints::none();

    foreach ($paths as $path) {
        $fingerprints = $fingerprints->with(Fingerprint::of(Path::of($path), Digest::sha256Of($path)));
    }

    return $fingerprints;
};

/**
 * The suite of a project with these files, and a package in `packages/a`.
 *
 * @param array<string, string> $files
 */
function suiteOf(array $files, Fingerprints $listed): Suite
{
    $project = Scratch::directory();

    foreach ($files as $path => $contents) {
        Scratch::write($project, $path, $contents);
    }

    $trees = Trees::of(
        Tree::at(Path::of('src'), Floor::of(50), Package::at(Path::root())),
        Tree::at(Path::of('packages/a/src'), Floor::of(50), Package::at(Path::of('packages/a'))),
    );
    $suite = Suite::read($trees, $listed, Directory::at($project));

    return $suite instanceof Suite ? $suite : throw new RuntimeException($suite->why());
}

it('reads each package\'s tests, and keeps every other file apart', function () use ($listed): void {
    $read = suiteOf(
        [
            'tests/MoneyTest.php' => "<?php\n",
            'tests/Pest.php' => "<?php\n",
            'packages/a/tests/LimitTest.php' => "<?php\n",
            'src/Money.php' => "<?php\n",
        ],
        $listed(
            'tests/MoneyTest.php',
            'tests/Pest.php',
            'packages/a/tests/LimitTest.php',
            'src/Money.php',
            'composer.json',
        ),
    );
    $roles = [];

    foreach ($read->files() as $file) {
        $roles[$file->fingerprint()->path()->value()] = $file->role();
    }

    expect($read->directories())->toEqual([SuiteDirectory::of(Path::of('tests'), '')])
        ->and($roles)->toBe([
            'tests/MoneyTest.php' => Role::TestCase,
            'tests/Pest.php' => Role::Support,
            'packages/a/tests/LimitTest.php' => Role::TestCase,
        ])
        ->and($read->outside())->toEqual($listed('src/Money.php', 'composer.json'))
        ->and($read->sources()->now(Path::of('tests/MoneyTest.php')))->toEqual(Contents::of("<?php\n"))
        ->and($read->sources()->now(Path::of('src/Money.php')))->toEqual(Missing::at(Path::of('src/Money.php')));
});

it('reads no file of the tests that is listed but gone', function () use ($listed): void {
    $read = suiteOf([], $listed('tests/GoneTest.php'));

    expect($read->files())->toHaveCount(0)
        ->and($read->outside())->toHaveCount(0);
});

it('cannot judge a test file it cannot read', function () use ($listed): void {
    $project = Scratch::directory();
    Scratch::write($project, 'tests/Nested.php/Inside.php', "<?php\n");

    expect(Suite::read(Flows::trees(), $listed('tests/Nested.php'), Directory::at($project)))
        ->toEqual(CannotJudge::because(sprintf('%s/tests/Nested.php could not be read.', $project)));
});

it('names the test files that name a group in either quote', function () use ($listed): void {
    $read = suiteOf(
        [
            'tests/SingleTest.php' => "<?php\nit('a', fn () => 1)->group('mutation-canary');\n",
            'tests/DoubleTest.php' => "<?php\nit('a', fn () => 1)->group(\"mutation-canary\");\n",
            'tests/OtherTest.php' => "<?php\nit('a', fn () => 1)->group('mutation-canary-not');\n",
            'tests/BareTest.php' => "<?php\n// mutation-canary\n",
        ],
        $listed('tests/SingleTest.php', 'tests/DoubleTest.php', 'tests/OtherTest.php', 'tests/BareTest.php'),
    );

    expect($read->naming(Group::named('mutation-canary')))
        ->toEqual(Paths::of(Path::of('tests/SingleTest.php'), Path::of('tests/DoubleTest.php')));
});

it('gathers what the #[Holds] of every test file declare', function () use ($listed): void {
    $class = "<?php\n\nnamespace Tests;\n\nuse NightWorksIO\\MutationGate\\Attribute\\Holds;\n\n"
        . "#[Holds('%s')]\nfinal class %s {}\n";
    $held = sprintf($class, 'src/Held.php', 'HeldTest');
    $kernel = sprintf($class, 'src/Kernel.php', 'KernelTest');
    $read = suiteOf(
        ['tests/HeldTest.php' => $held, 'tests/KernelTest.php' => $kernel, 'tests/MoneyTest.php' => "<?php\n"],
        $listed('tests/HeldTest.php', 'tests/KernelTest.php', 'tests/MoneyTest.php', 'tests/GoneTest.php'),
    );

    expect($read->holdings())->toEqual(Holdings::none()
        ->with(Holding::byAttribute('src/Held.php', Holder::of('Tests\HeldTest')))
        ->with(Holding::byAttribute('src/Kernel.php', Holder::of('Tests\KernelTest'))));
});

it('declares no holding where no test file holds anything', function () use ($listed): void {
    expect(suiteOf(['tests/MoneyTest.php' => "<?php\n"], $listed('tests/MoneyTest.php'))->holdings()->anyByAttribute())
        ->toBeFalse();
});

it('keeps each test file as what it holds', function () use ($listed): void {
    $read = suiteOf(['tests/MoneyTest.php' => "<?php\nfinal class MoneyTest {}\n"], $listed('tests/MoneyTest.php'));
    $files = [...$read->files()];

    expect($files[0])->toEqual(TestFile::testCase(
        Fingerprint::of(Path::of('tests/MoneyTest.php'), Digest::sha256Of('tests/MoneyTest.php')),
        Contents::of("<?php\nfinal class MoneyTest {}\n"),
    ));
});

it('reads the tests the PHPUnit config declares, and leaves out what it excludes, #[Holds] and all', function () use (
    $listed,
): void {
    $config = <<<'XML'
        <?xml version="1.0"?>
        <phpunit>
            <testsuites>
                <testsuite name="Unit">
                    <directory>tests</directory>
                    <exclude>tests/Contract/fixture</exclude>
                </testsuite>
                <testsuite name="Specs"><directory suffix="Spec.php">spec</directory></testsuite>
            </testsuites>
        </phpunit>
        XML;
    $held = <<<'PHP'
        <?php

        use NightWorksIO\MutationGate\Attribute\Holds;

        #[Holds('src/Shapes.php')]
        final class ShapesCaseSpec {}

        PHP;
    $read = suiteOf(
        [
            'phpunit.xml' => $config,
            'tests/MoneyTest.php' => "<?php\n",
            'tests/Contract/fixture/tests/ShapesCaseSpec.php' => $held,
            'spec/LimitSpec.php' => "<?php\n",
            'spec/Helper.php' => "<?php\n",
        ],
        $listed(
            'tests/MoneyTest.php',
            'tests/Contract/fixture/tests/ShapesCaseSpec.php',
            'spec/LimitSpec.php',
            'spec/Helper.php',
        ),
    );
    $roles = [];

    foreach ($read->files() as $file) {
        $roles[$file->fingerprint()->path()->value()] = $file->role();
    }

    expect($roles)->toBe([
        'tests/MoneyTest.php' => Role::TestCase,
        'spec/LimitSpec.php' => Role::TestCase,
        'spec/Helper.php' => Role::Support,
    ])
        ->and($read->holdings()->anyByAttribute())->toBeFalse()
        ->and($read->directories())->toEqual([
            SuiteDirectory::of(Path::of('tests'), ''),
            SuiteDirectory::of(Path::of('spec'), 'Spec.php'),
        ]);
});

it('cannot judge a suite whose PHPUnit config cannot be read', function () use ($listed): void {
    $project = Scratch::directory();
    Scratch::write($project, 'phpunit.xml', '<phpunit><testsuites>');
    $unreadable = Scratch::directory();
    mkdir(sprintf('%s/phpunit.xml', $unreadable));

    expect(Suite::read(Flows::trees(), $listed(), Directory::at($project)))
        ->toEqual(CannotJudge::because('phpunit.xml is not XML, so the test suite it declares cannot be read.'))
        ->and(Suite::read(Flows::trees(), $listed(), Directory::at($unreadable)))
        ->toEqual(CannotJudge::because(sprintf('%s/phpunit.xml could not be read.', $unreadable)));
});
