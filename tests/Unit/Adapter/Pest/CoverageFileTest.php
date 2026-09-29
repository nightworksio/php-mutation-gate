<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
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

/** A map of two files under /nowhere/src/, with three tests, written as --coverage-php writes it. */
$written = static function (): string {
    $file = sprintf('%s/coverage.php', Scratch::directory());
    CoverageMaps::write(
        $file,
        '/nowhere/src/',
        [
            'Money.php' => [10 => [0], 11 => [0, 1], 12 => [1, 2], 13 => [], 14 => [2]],
            'Held.php' => [5 => [2]],
        ],
        [
            'P\Tests\MoneySpec::__pest_evaluable_it_adds',
            'Tests\MoneyTest::testLarge',
            'P\Tests\HeldSpec::__pest_evaluable_it_doubles',
        ],
        ['P\Tests\MoneySpec::__pest_evaluable_it_adds' => 0.25, 'Tests\MoneyTest::testLarge' => 1.5],
    );

    return $file;
};

it('reads which tests ran each line, and how long each test took', function () use ($written): void {
    $coverage = CoverageFile::at($written());
    $adds = TestId::of('P\Tests\MoneySpec::__pest_evaluable_it_adds');
    $large = TestId::of('Tests\MoneyTest::testLarge');
    $doubles = TestId::of('P\Tests\HeldSpec::__pest_evaluable_it_doubles');
    $money = Path::of('src/Money.php');

    $project = Project::at('/nowhere', Paths::none(), Path::of('.mutation-gate'));

    expect($coverage instanceof CoverageFile ? $coverage->map($project) : $coverage)
        ->toEqual(CoverageMap::empty()
            ->covered($money, Line::of(10), $adds)
            ->covered($money, Line::of(11), $adds)
            ->covered($money, Line::of(11), $large)
            ->covered($money, Line::of(12), $large)
            ->covered($money, Line::of(12), $doubles)
            ->covered($money, Line::of(14), $doubles)
            ->covered(Path::of('src/Held.php'), Line::of(5), $doubles)
            ->timed($adds, Seconds::of(0.25))
            ->timed($large, Seconds::of(1.5)));
});

it('names each test that ran a range of lines once, by the file\'s path on disk', function () use ($written): void {
    $coverage = CoverageFile::at($written());

    expect($coverage instanceof CoverageFile ? $coverage->testsCovering('/nowhere/src/Money.php', 11, 13) : [])->toBe([
        'P\Tests\MoneySpec::__pest_evaluable_it_adds',
        'Tests\MoneyTest::testLarge',
        'P\Tests\HeldSpec::__pest_evaluable_it_doubles',
    ])
        ->and($coverage instanceof CoverageFile ? $coverage->testsCovering('/nowhere/src/Money.php', 14, 14) : [])
        ->toBe(['P\Tests\HeldSpec::__pest_evaluable_it_doubles'])
        ->and($coverage instanceof CoverageFile ? $coverage->testsCovering('/nowhere/src/Money.php', 10, 10) : [])
        ->toBe(['P\Tests\MoneySpec::__pest_evaluable_it_adds'])
        ->and($coverage instanceof CoverageFile ? $coverage->testsCovering('/nowhere/src/Nowhere.php', 1, 99) : [0])
        ->toBe([]);
});

it('adds up how long the whole suite took, one test after another', function () use ($written): void {
    $coverage = CoverageFile::at($written());

    expect($coverage instanceof CoverageFile ? $coverage->seconds() : 0.0)->toBe(1.75);
});

it('cannot judge without a map', function (): void {
    expect(CoverageFile::at('/nowhere/coverage.php'))
        ->toEqual(CannotJudge::because('There is no coverage map at /nowhere/coverage.php, so no test runs any line.'));
});

it('cannot judge a file that is not a coverage map', function (): void {
    $file = sprintf('%s/coverage.php', Scratch::directory());
    file_put_contents($file, '<?php return [];');

    expect(CoverageFile::at($file))->toEqual(CannotJudge::because(sprintf(
        '%s cannot be read as a coverage map: '
        . 'File does not contain phpunit/php-code-coverage serialization format information: %s',
        $file,
        $file,
    )));
});
