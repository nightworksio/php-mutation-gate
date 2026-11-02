<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Native\Coverage;
use NightWorksIO\MutationGate\Adapter\Native\Invocation;
use NightWorksIO\MutationGate\Adapter\Native\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\PhpReport;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Native\Command;
use NightWorksIO\MutationGate\Core\Native\TestTimes;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
use NightWorksIO\MutationGate\Tests\Support\GzipBomb;
use NightWorksIO\MutationGate\Tests\Support\NativePhpUnit;
use NightWorksIO\MutationGate\Tests\Support\NativeTesto;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

function coverageProject(): Project
{
    return Project::at(Scratch::directory(), Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'), BuiltinRunner::PhpUnit);
}

/** A shell whose PHPUnit writes a map of one covered line to the file its command names, and ends so. */
function mapping(Project $project, bool $succeeded): PhpUnitShellFake
{
    return new PhpUnitShellFake(static function (Command $command) use ($project, $succeeded): Ran {
        $option = array_values(array_filter($command->arguments(), static fn(string $a): bool => str_starts_with($a, '--coverage-php=')));
        $file = substr($option[0], strlen('--coverage-php='));
        if ($file !== '') {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), recursive: true);
            }
            CoverageMaps::write(
                $file,
                sprintf('%s/', $project->root()),
                ['src/Money.php' => [4 => [0]]],
                ['Tests\MoneyTest::testAdds'],
                ['Tests\MoneyTest::testAdds' => 0.5],
            );
        }

        return Ran::finished($succeeded, 'PHPUnit said this');
    });
}

it('runs PHPUnit under coverage and reads the map it wrote', function (): void {
    $project = coverageProject();
    $shell = mapping($project, succeeded: true);
    $withheld = Withheld::standard()->and(Withheld::of('SECRET'));
    $map = new Coverage($project, $shell, new Invocation($project, '/gate/override.php', NativePhpUnit::in($project)), NativePhpUnit::in($project))
        ->of(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'))->withholding($withheld));
    $command = $shell->commands()[0];
    $adds = TestId::of('Tests\MoneyTest::testAdds');

    expect($map)->toEqual(CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(4), $adds)->timed($adds, Seconds::of(0.5)))
        ->and($command->arguments())->toBe([
            PHP_BINARY,
            '-d',
            sprintf('pcov.directory=%s', $project->root()),
            '-d',
            sprintf('pcov.exclude=~^%s/vendor/~', preg_quote($project->root(), '~')),
            sprintf('%s/vendor/bin/phpunit', $project->root()),
            sprintf('--coverage-php=%s/.mutation-gate/coverage/coverage.php', $project->root()),
            '--no-logging',
            '--do-not-record-test-run-history',
            sprintf('--cache-directory=%s/.mutation-gate/phpunit-cache', $project->root()),
            '--no-progress',
        ])
        ->and($command->withheld())->toEqual($withheld)
        ->and($command->deadline())->toEqual(Unlimited::time());
});

it('keeps a coverage run to a group, the tests a filter names, or some test files', function (Group|Filter|TestPaths $tests, array $options): void {
    $project = coverageProject();
    $shell = mapping($project, succeeded: true);
    new Coverage($project, $shell, new Invocation($project, '/gate/override.php', NativePhpUnit::in($project)), NativePhpUnit::in($project))
        ->of(CoverageRun::of($tests, Path::of('.mutation-gate/coverage')));

    expect(array_slice($shell->commands()[0]->arguments(), -2))->toBe($options);
})->with([
    'a group' => [fn(): Group => Group::named('holds:src/Money.php'), ['--group', 'holds:src/Money.php']],
    'a filter' => [fn(): Filter => Filter::matching('Money'), ['--filter', 'Money']],
    'some test files' => [
        fn(): TestPaths => TestPaths::of(Paths::of(Path::of('tests/MoneyTest.php'), Path::of('tests/HeldTest.php'))),
        ['tests/MoneyTest.php', 'tests/HeldTest.php'],
    ],
]);

it('removes an earlier run\'s map before it runs, so a failed run never reads one', function (): void {
    $project = coverageProject();
    $map = sprintf('%s/.mutation-gate/coverage/coverage.php', $project->root());
    Scratch::write($project->root(), '.mutation-gate/coverage/coverage.php', 'an earlier map');
    $shell = PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $read = new Coverage($project, $shell, new Invocation($project, '/gate/override.php', NativePhpUnit::in($project)), NativePhpUnit::in($project))
        ->of(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));

    expect($read)->toEqual(CannotJudge::because(sprintf('There is no coverage map at %s, so no test runs any line.', $map)));
});

it('cannot judge a run that failed, or an earlier map it cannot remove', function (): void {
    $project = coverageProject();
    $shell = mapping($project, succeeded: false);
    $coverage = new Coverage($project, $shell, new Invocation($project, '/gate/override.php', NativePhpUnit::in($project)), NativePhpUnit::in($project));
    $failed = $coverage->of(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));
    Scratch::write($project->root(), '.mutation-gate/stuck/coverage.php/inside', 'a directory where the map goes');

    expect($failed)->toEqual(CannotJudge::because("PHPUnit's coverage run failed. PHPUnit said:\nPHPUnit said this"))
        ->and($coverage->of(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/stuck'))))
        ->toEqual(CannotJudge::because(sprintf(
            'An earlier run left %s/.mutation-gate/stuck/coverage.php, and the gate cannot remove it.',
            $project->root(),
        )))
        ->and($shell->commands())->toHaveCount(1);
});

it('reads the map another job handed over, or says there is none', function (): void {
    $project = coverageProject();
    $adds = TestId::of('Tests\MoneyTest::testAdds');
    $handed = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(4), $adds)->timed($adds, Seconds::of(0.5));
    $file = $project->absolute(CoverageMapFile::in(Path::of('handed')));
    Scratch::write($project->root(), CoverageMapFile::in(Path::of('handed'))->value(), CoverageMapFile::encode($handed, Unplaced::map()));
    $shell = PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $coverage = new Coverage($project, $shell, new Invocation($project, '/gate/override.php', NativePhpUnit::in($project)), NativePhpUnit::in($project));

    expect($coverage->of(CoverageRead::from(Path::of('handed'))))->toEqual($handed)
        ->and($coverage->of(CoverageRead::from(Path::of('none'))))
        ->toEqual(CoverageMapFile::missingAt($project->absolute(CoverageMapFile::in(Path::of('none')))))
        ->and($file)->toBeFile()
        ->and($shell->commands())->toBe([]);
});

it('reads a handed-over map within this process\'s share of its memory', function (): void {
    $project = coverageProject();
    $bomb = GzipBomb::padded(256 * 1_048_576);
    Scratch::write($project->root(), CoverageMapFile::in(Path::of('bomb'))->value(), $bomb);
    $coverage = new Coverage($project, PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: '')), new Invocation($project, '/gate/override.php', NativePhpUnit::in($project)), NativePhpUnit::in($project));
    $limit = GzipBomb::limitAboveUse();
    $share = intdiv(ini_parse_quantity($limit), 23);
    $read = GzipBomb::readUnder($limit, static fn(): CoverageMap|CannotJudge => $coverage->of(CoverageRead::from(Path::of('bomb'))));

    expect($share)->toBeLessThan(300 * strlen($bomb))
        ->and(ini_get('memory_limit'))->not->toBe($limit)
        ->and($read)->toEqual(CannotJudge::because(sprintf('The coverage map inflates to more than %d bytes.', $share)));
});

it('reads the report a coverage run this job started itself left in a directory, running nothing', function (): void {
    $project = coverageProject();
    $adds = TestId::of('Tests\MoneyTest::testAdds');
    mkdir(sprintf('%s/build/suite', $project->root()), recursive: true);
    CoverageMaps::write(
        sprintf('%s/build/suite/coverage.php', $project->root()),
        sprintf('%s/', $project->root()),
        ['src/Money.php' => [4 => [0]]],
        ['Tests\MoneyTest::testAdds'],
        ['Tests\MoneyTest::testAdds' => 0.5],
    );
    $shell = PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $coverage = new Coverage($project, $shell, new Invocation($project, '/gate/override.php', NativePhpUnit::in($project)), NativePhpUnit::in($project));

    expect($coverage->of(CoverageRan::in(Path::of('build/suite'))))
        ->toEqual(CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(4), $adds)->timed($adds, Seconds::of(0.5)))
        ->and($coverage->of(CoverageRan::in(Path::of('build/none'))))
        ->toEqual(PhpReport::missingAt(sprintf('%s/build/none/coverage.php', $project->root())))
        ->and($shell->commands())->toBe([]);
});

/**
 * A shell whose Testo writes its XML coverage of one covered line into the
 * directory its command names, and the gate's plugin the time of each test
 * beside it, and ends so.
 */
function testoMapping(Project $project, bool $timed): PhpUnitShellFake
{
    return new PhpUnitShellFake(static function (Command $command) use ($project, $timed): Ran {
        $option = array_values(array_filter($command->arguments(), static fn(string $a): bool => str_starts_with($a, '--coverage-xml=')));
        $directory = substr($option[0], strlen('--coverage-xml='));
        Scratch::write($directory, 'index.xml', sprintf(
            '<phpunit><project source="%s"><directory><file name="Money.php" href="src/Money.php.xml"/></directory></project></phpunit>',
            $project->root(),
        ));
        Scratch::write($directory, 'src/Money.php.xml', '<phpunit><file name="Money.php" path="src"><coverage><line nr="4"><covered by="Tests\MoneyTest::adds"/></line></coverage></file></phpunit>');

        if ($timed) {
            Scratch::write(dirname($directory), 'times.txt', TestTimes::line('Tests\MoneyTest::adds', 0.5));
        }

        return Ran::finished(succeeded: true, output: 'Testo said this');
    });
}

/** The project's Testo, its coverage measured through this shell. */
function testoCoverage(Project $project, PhpUnitShellFake $shell): Coverage
{
    return new Coverage($project, $shell, new Invocation($project, '/gate/override.php', NativeTesto::in($project)), NativeTesto::in($project));
}

it('runs Testo under coverage and reads its XML coverage, timed as the gate\'s plugin timed each test', function (): void {
    $project = coverageProject();
    $adds = TestId::of('Tests\MoneyTest::adds');
    $run = CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'));
    $covered = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(4), $adds);

    expect(testoCoverage($project, testoMapping($project, timed: true))->of($run))->toEqual($covered->timed($adds, Seconds::of(0.5)))
        ->and(testoCoverage($project, testoMapping($project, timed: false))->of($run))->toEqual($covered);
});

it('removes an earlier run\'s XML index and times before it runs, and cannot judge an index it cannot remove', function (): void {
    $project = coverageProject();
    Scratch::write($project->root(), '.mutation-gate/coverage/coverage-xml/index.xml', 'an earlier index');
    Scratch::write($project->root(), '.mutation-gate/coverage/times.txt', TestTimes::line('Tests\MoneyTest::old', 9.0));
    Scratch::write($project->root(), '.mutation-gate/stuck/coverage-xml/index.xml/inside', 'a directory where the index goes');
    $shell = PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $coverage = testoCoverage($project, $shell);

    expect($coverage->of(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'))))->toEqual(CannotJudge::because(sprintf(
        '%s/.mutation-gate/coverage/coverage-xml/index.xml is not there or is not PHPUnit XML coverage, so the gate cannot say which tests run which line.',
        $project->root(),
    )))
        ->and(is_file(sprintf('%s/.mutation-gate/coverage/times.txt', $project->root())))->toBeFalse()
        ->and($coverage->of(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/stuck'))))->toEqual(CannotJudge::because(sprintf(
            'An earlier run left %s/.mutation-gate/stuck/coverage-xml/index.xml, and the gate cannot remove it.',
            $project->root(),
        )))
        ->and($shell->commands())->toHaveCount(1);
});

it('reads the XML coverage a Testo run this job started itself left in a directory, untimed, running nothing', function (): void {
    $project = coverageProject();
    $shell = testoMapping($project, timed: false);
    $shell->run(Command::php(sprintf('--coverage-xml=%s/build/suite/coverage-xml', $project->root())));
    $coverage = testoCoverage($project, PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: '')));

    expect($coverage->of(CoverageRan::in(Path::of('build/suite'))))
        ->toEqual(CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(4), TestId::of('Tests\MoneyTest::adds')));
});
