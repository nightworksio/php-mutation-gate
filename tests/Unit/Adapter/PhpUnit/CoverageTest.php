<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\Command;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Coverage;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Invocation;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitShellFake;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

function coverageProject(): Project
{
    return Project::at(Scratch::directory(), Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
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
    $map = new Coverage($project, $shell, new Invocation($project, '/gate/override.php'))
        ->of(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage'))->withholding($withheld));
    $command = $shell->commands()[0];
    $adds = TestId::of('Tests\MoneyTest::testAdds');

    expect($map)->toEqual(CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(4), $adds)->timed($adds, Seconds::of(0.5)))
        ->and($command->arguments())->toBe([
            PHP_BINARY,
            sprintf('%s/vendor/bin/phpunit', $project->root()),
            sprintf('--coverage-php=%s/.mutation-gate/coverage/coverage.php', $project->root()),
            '--no-logging',
            '--do-not-cache-result',
            '--no-progress',
        ])
        ->and($command->withheld())->toEqual($withheld)
        ->and($command->deadline())->toEqual(Unlimited::time());
});

it('keeps a coverage run to a group, or to the tests a filter names', function (Group|Filter $tests, array $options): void {
    $project = coverageProject();
    $shell = mapping($project, succeeded: true);
    new Coverage($project, $shell, new Invocation($project, '/gate/override.php'))
        ->of(CoverageRun::of($tests, Path::of('.mutation-gate/coverage')));

    expect(array_slice($shell->commands()[0]->arguments(), -2))->toBe($options);
})->with([
    'a group' => [Group::named('holds:src/Money.php'), ['--group', 'holds:src/Money.php']],
    'a filter' => [Filter::matching('Money'), ['--filter', 'Money']],
]);

it('removes an earlier run\'s map before it runs, so a failed run never reads one', function (): void {
    $project = coverageProject();
    $map = sprintf('%s/.mutation-gate/coverage/coverage.php', $project->root());
    Scratch::write($project->root(), '.mutation-gate/coverage/coverage.php', 'an earlier map');
    $shell = PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $read = new Coverage($project, $shell, new Invocation($project, '/gate/override.php'))
        ->of(CoverageRun::of(WholeSuite::tests(), Path::of('.mutation-gate/coverage')));

    expect($read)->toEqual(CannotJudge::because(sprintf('There is no coverage map at %s, so no test runs any line.', $map)));
});

it('cannot judge a run that failed, or an earlier map it cannot remove', function (): void {
    $project = coverageProject();
    $shell = mapping($project, succeeded: false);
    $coverage = new Coverage($project, $shell, new Invocation($project, '/gate/override.php'));
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
    Scratch::write($project->root(), CoverageMapFile::in(Path::of('handed'))->value(), CoverageMapFile::encode($handed));
    $shell = PhpUnitShellFake::answering(Ran::finished(succeeded: true, output: ''));
    $coverage = new Coverage($project, $shell, new Invocation($project, '/gate/override.php'));

    expect($coverage->of(CoverageRead::from(Path::of('handed'))))->toEqual($handed)
        ->and($coverage->of(CoverageRead::from(Path::of('none'))))
        ->toEqual(CoverageMapFile::missingAt($project->absolute(CoverageMapFile::in(Path::of('none')))))
        ->and($file)->toBeFile()
        ->and($shell->commands())->toBe([]);
});
