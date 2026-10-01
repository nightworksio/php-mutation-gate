<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\CoverageFile;
use NightWorksIO\MutationGate\Adapter\PhpUnit\Project;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\CoverageMaps;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

function coverageFileProject(): Project
{
    return Project::at(Scratch::directory(), Paths::of(Path::of('tests')), Path::of('vendor'), Path::of('.mutation-gate'));
}

it('reads which tests ran each line of each file of the project, and how long each test took', function (): void {
    $project = coverageFileProject();
    $file = sprintf('%s/coverage.php', $project->root());
    CoverageMaps::write(
        $file,
        sprintf('%s/src/', $project->root()),
        ['Money.php' => [10 => [0], 11 => [0, 1], 13 => []], 'Held.php' => [5 => [1]]],
        ['Tests\MoneyTest::testAdds', 'Tests\MoneyTest::testLarge#0'],
        ['Tests\MoneyTest::testAdds' => 0.25, 'Tests\MoneyTest::testLarge#0' => 1.5],
    );
    $adds = TestId::of('Tests\MoneyTest::testAdds');
    $large = TestId::of('Tests\MoneyTest::testLarge#0');
    $money = Path::of('src/Money.php');

    expect(CoverageFile::read($project, $file))->toEqual(CoverageMap::empty()
        ->covered($money, Line::of(10), $adds)
        ->covered($money, Line::of(11), $adds)
        ->covered($money, Line::of(11), $large)
        ->covered(Path::of('src/Held.php'), Line::of(5), $large)
        ->timed($adds, Seconds::of(0.25))
        ->timed($large, Seconds::of(1.5)));
});

it('cannot judge without a map, or with one cut off or not a map', function (): void {
    $project = coverageFileProject();
    $file = sprintf('%s/coverage.php', $project->root());
    $missing = CoverageFile::read($project, $file);
    file_put_contents($file, '<?php return [];');
    $cutOff = CoverageFile::read($project, $file);
    file_put_contents($file, "<?php return [];\nEND_OF_COVERAGE_SERIALIZATION\n);\n");

    expect($missing)->toEqual(CannotJudge::because(sprintf('There is no coverage map at %s, so no test runs any line.', $file)))
        ->and($cutOff)->toEqual(CannotJudge::because(sprintf('%s cannot be read as a coverage map: it ends before the map does.', $file)))
        ->and(CoverageFile::read($project, $file))->toEqual(CannotJudge::because(sprintf(
            '%s cannot be read as a coverage map: File does not contain phpunit/php-code-coverage serialization format information: %s',
            $file,
            $file,
        )));
});
