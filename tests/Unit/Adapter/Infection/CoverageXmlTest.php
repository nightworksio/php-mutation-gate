<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Infection\CoverageXml;
use NightWorksIO\MutationGate\Adapter\Infection\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
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
    $project = Project::at($root, Paths::none(), Path::of('.gate'));

    expect(CoverageXml::read($project, sprintf('%s/coverage', $root)))->toEqual(CoverageMap::empty()
        ->covered(Path::of('src/Money.php'), Line::of(11), TestId::of('Tests\MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(16), TestId::of('Tests\MoneyTest::adds'))
        ->covered(Path::of('src/Money.php'), Line::of(16), TestId::of('Tests\MoneyTest::large'))
        ->covered(Path::of('Held.php'), Line::of(11), TestId::of('Tests\HeldTest::doubles'))
        ->timed(TestId::of('Tests\MoneyTest::adds'), Seconds::of(0.25))
        ->timed(TestId::of('Tests\MoneyTest::large'), Seconds::of(0.125)));
});

it('cannot judge coverage whose index names a file report that is not there', function (): void {
    $root = (string) realpath(Scratch::directory());
    InfectionRun::coverage(sprintf('%s/coverage', $root), $root, ['A.php' => [3 => ['T::a']]], [], []);
    Scratch::write($root, 'coverage/coverage-xml/index.xml', str_replace(
        '<file name="A.php"',
        '<file name="Gone.php" href="Gone.php.xml"/><file name="A.php"',
        (string) file_get_contents(sprintf('%s/coverage/coverage-xml/index.xml', $root)),
    ));
    $project = Project::at($root, Paths::none(), Path::of('.gate'));

    expect(CoverageXml::read($project, sprintf('%s/coverage', $root)))->toEqual(CannotJudge::because(sprintf(
        '%s/coverage/coverage-xml/Gone.php.xml is not there or is not PHPUnit XML coverage, so the gate cannot say which tests run which line.',
        $root,
    )));
});

it('cannot judge a directory with no XML coverage, or with an index that is not XML', function (): void {
    $root = (string) realpath(Scratch::directory());
    Scratch::write($root, 'broken/coverage-xml/index.xml', '<phpunit');
    Scratch::write($root, 'broken/junit.xml', '<testsuites/>');
    Scratch::write($root, 'empty/junit.xml', '<testsuites/>');
    $project = Project::at($root, Paths::none(), Path::of('.gate'));

    foreach (['broken', 'empty'] as $name) {
        expect(CoverageXml::read($project, sprintf('%s/%s', $root, $name)))->toEqual(CannotJudge::because(sprintf(
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
    $project = Project::at($root, Paths::none(), Path::of('.gate'));

    expect(CoverageXml::read($project, sprintf('%s/coverage', $root)))->toEqual(CannotJudge::because(sprintf(
        '%s/coverage/junit.xml is not there or is not a JUnit log, so the gate cannot say how long the tests took.',
        $root,
    )));
});
