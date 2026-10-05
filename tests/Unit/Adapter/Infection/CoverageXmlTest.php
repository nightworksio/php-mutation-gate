<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\CoverageXml;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethod;
use NightWorksIO\MutationGate\Core\Coverage\ExecutedMethods;
use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Growth;
use NightWorksIO\MutationGate\Tests\Support\InfectionRun;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('reads which tests cover which line of each file, as the project spells it, and how long each test took', function (): void {
    $root = (string) realpath(Scratch::directory());
    InfectionRun::coverage(sprintf('%s/coverage', $root), $root, [
        'src/Money.php' => [11 => ['Tests\MoneyTest::adds'], 16 => ['Tests\MoneyTest::adds', 'Tests\MoneyTest::large']],
        'Held.php' => [11 => ['Tests\HeldTest::doubles'], 12 => []],
    ], ['Tests\MoneyTest' => 0.5], ['Tests\MoneyTest::adds' => 0.25, 'Tests\MoneyTest::large' => 0.125]);
    $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));

    expect(CoverageXml::read($project, DiskPath::of(sprintf('%s/coverage', $root))))->toEqual(CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(16), TestId::of('Tests\MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(16), TestId::of('Tests\MoneyTest::large'))
        ->covered(Path::of('Held.php'), Line::of(11), TestId::of('Tests\HeldTest::doubles'))
        ->timed(TestId::of('Tests\MoneyTest::adds'), Seconds::of(0.25))
        ->timed(TestId::of('Tests\MoneyTest::large'), Seconds::of(0.125)));
});

/** A coverage directory of one file's report, holding these classes and traits, each with its methods. */
function coverageXmlOfUnits(string $root, string $units): string
{
    Scratch::write($root, 'coverage/coverage-xml/index.xml', sprintf(
        '<?xml version="1.0"?><phpunit xmlns="https://schema.phpunit.de/coverage/1.0"><project source="%s"><directory name="/"><file name="A.php" href="A.php.xml"/></directory></project></phpunit>',
        $root,
    ));
    Scratch::write($root, 'coverage/coverage-xml/A.php.xml', sprintf(
        '<?xml version="1.0"?><phpunit xmlns="https://schema.phpunit.de/coverage/1.0"><file name="A.php" path="/">%s<coverage><line nr="3"><covered by="T::a"/></line></coverage></file></phpunit>',
        $units,
    ));
    Scratch::write($root, 'coverage/junit.xml', '<testsuites/>');

    return sprintf('%s/coverage', $root);
}

it('reads the methods of a file\'s classes some test ran, as Infection reads them', function (string $units, ExecutedMethod ...$expected): void {
    $root = (string) realpath(Scratch::directory());
    $map = CoverageXml::read(Project::at(Root::of($root), Paths::none(), Path::of('.gate')), DiskPath::of(coverageXmlOfUnits($root, $units)));

    expect($map instanceof CoverageMap ? $map->methods()->at(Path::of('A.php'), ExecutedMethods::none()) : $map)->toEqual(ExecutedMethods::of(...$expected));
})->with([
    'a class\'s methods, less one under one percent' => [
        '<class name="A"><method name="add" start="3" end="5" coverage="100"/><method name="few" start="6" end="9" coverage="0.5"/>'
        . '<method name="none" start="10" end="12" coverage="0"/><method name="half" start="13" end="20" coverage="50.00"/></class>',
        ExecutedMethod::of('add', 3, 5),
        ExecutedMethod::of('half', 13, 20),
    ],
    'a trait\'s, where the classes have no method' => [
        '<class name="A"/><trait name="T"><method name="use" start="2" end="4" coverage="100"/></trait>',
        ExecutedMethod::of('use', 2, 4),
    ],
    'no trait\'s, where a class has a method no test ran' => [
        '<class name="A"><method name="none" start="10" end="12" coverage="0"/></class><trait name="T"><method name="use" start="2" end="4" coverage="100"/></trait>',
    ],
    'none outside a class or trait' => ['<function name="f" start="1" end="2" coverage="100"/><method name="loose" start="1" end="2" coverage="100"/>'],
]);

it('cannot judge coverage whose index names a file report that is not there', function (): void {
    $root = (string) realpath(Scratch::directory());
    InfectionRun::coverage(sprintf('%s/coverage', $root), $root, ['A.php' => [3 => ['T::a']]], [], []);
    Scratch::write($root, 'coverage/coverage-xml/index.xml', str_replace(
        '<file name="A.php"',
        '<file name="Gone.php" href="Gone.php.xml"/><file name="A.php"',
        (string) file_get_contents(sprintf('%s/coverage/coverage-xml/index.xml', $root)),
    ));
    $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));

    expect(CoverageXml::read($project, DiskPath::of(sprintf('%s/coverage', $root))))->toEqual(CannotJudge::because(sprintf(
        '%s/coverage/coverage-xml/Gone.php.xml is not there or is not PHPUnit XML coverage, so the gate cannot say which tests run which line.',
        $root,
    )));
});

it('cannot judge a directory with no XML coverage, or with an index that is not XML', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'broken/coverage-xml/index.xml', '<phpunit');
    Scratch::write($root, 'broken/junit.xml', '<testsuites/>');
    Scratch::write($root, 'empty/junit.xml', '<testsuites/>');
    $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));

    foreach (['broken', 'empty'] as $name) {
        expect(CoverageXml::read($project, DiskPath::of(sprintf('%s/%s', $root, $name))))->toEqual(CannotJudge::because(sprintf(
            '%s/%s/coverage-xml/index.xml is not there or is not PHPUnit XML coverage, so the gate cannot say which tests run which line.',
            $root,
            $name,
        )));
    }
});

it('cannot judge coverage without its JUnit log', function (): void {
    $root = (string) realpath(Scratch::directory());
    InfectionRun::coverage(sprintf('%s/coverage', $root), $root, [], [], []);
    unlink(sprintf('%s/coverage/junit.xml', $root));
    $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));

    expect(CoverageXml::read($project, DiskPath::of(sprintf('%s/coverage', $root))))->toEqual(CannotJudge::because(sprintf(
        '%s/coverage/junit.xml is not there or is not a JUnit log, so the gate cannot say how long the tests took.',
        $root,
    )));
});

it('reads a coverage directory in time linear in its covered lines and tests', function (): void {
    $read = static function (int $size): Closure {
        $root = (string) realpath(Scratch::directory());
        $lines = [];

        for ($line = 1; $line <= $size; $line++) {
            $lines[$line] = array_map(static fn(int $test): string => sprintf('Tests\\MoneyTest::t%d', ($line + $test) % ($size + 1)), range(1, 8));
        }

        $times = array_fill_keys(array_map(static fn(int $test): string => sprintf('Tests\\MoneyTest::t%d', $test), range(0, $size)), 0.25);
        $directory = sprintf('%s/coverage-%d', $root, $size);
        InfectionRun::coverage($directory, $root, ['src/Money.php' => $lines], ['Tests\\MoneyTest' => 0.5], $times);
        $project = Project::at(Root::of($root), Paths::none(), Path::of('.gate'));

        return static function () use ($project, $directory): int {
            $map = CoverageXml::read($project, DiskPath::of($directory));

            return $map instanceof CoverageMap ? count($map->tests()) : 0;
        };
    };

    expect($read(10)())->toBe(11)
        ->and(Growth::of(1000, $read))->toBeLessThan(Growth::LINEAR);
});
