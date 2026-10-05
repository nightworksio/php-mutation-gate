<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\CoverageFile;
use NightWorksIO\MutationGate\Adapter\Pest\Project;
use NightWorksIO\MutationGate\Adapter\Pest\SharedCoverage;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

/** A project in a new directory. */
function sharedProject(): Project
{
    return Project::at((string) realpath(Scratch::directory()), Paths::none(), Path::of('.mutation-gate'), Path::of('vendor'));
}

/** A map of two lines of Money, the first line of it among them, by two timed tests. */
function sharedMap(): CoverageMap
{
    return CoverageMap::of(
        CoveredLine::of(Path::of('src/Money.php'), 1, 'MoneyTest::adds'),
        CoveredLine::of(Path::of('src/Money.php'), 12, 'MoneyTest::adds', 'MoneyTest::subtracts'),
    )->timedEach(TimedTest::of('MoneyTest::adds', 0.25), TimedTest::of('MoneyTest::subtracts', 0.5));
}

it('reads the gate\'s own map another job handed over, and nothing else there', function (): void {
    $at = sharedProject();
    Scratch::write($at->root(), 'planned/map.json.gz', CoverageMapFile::encode(sharedMap(), Unplaced::map()));
    Scratch::write($at->root(), 'foreign/coverage.php', '<?php return [];');
    Scratch::write($at->root(), 'spoilt/map.json.gz', 'not gzip');

    expect(SharedCoverage::in($at, Path::of('planned')))->toEqual(sharedMap())
        ->and(SharedCoverage::in($at, Path::of('foreign')))->toEqual(CannotJudge::because(sprintf(
            'The gate wrote no coverage map at %s/foreign/map.json.gz, and reads no runner\'s map another job wrote.',
            $at->root(),
        )))
        ->and(SharedCoverage::in($at, Path::of('spoilt')))->toBeInstanceOf(CannotJudge::class);
});

it('adds up the seconds of the tests it timed', function (): void {
    expect(SharedCoverage::seconds(sharedMap()))->toBe(0.75)
        ->and(SharedCoverage::seconds(sharedMap()->timedEach()))->toBe(0.75)
        ->and(SharedCoverage::seconds(CoverageMap::of(CoveredLine::of(Path::of('a.php'), 3, 'T::t'))))->toBe(0.0);
});

it('writes a map as --coverage-php writes one, which reads back as the same map', function (): void {
    $at = sharedProject();
    $target = sprintf('%s/shared.coverage.php', $at->root());
    $untimed = sharedMap()->covered(Path::of('src/Money.php'), Line::of(14), TestId::of(''))
        ->covered(Path::of('src/Tax.php'), Line::of(3), TestId::of('TaxTest::rates'));

    SharedCoverage::write($untimed, $at, $target);
    $read = CoverageFile::at($target);

    expect($read instanceof CoverageFile ? $read->map($at) : $read)->toEqual(CoverageMap::of(
        CoveredLine::of(Path::of('src/Money.php'), 1, 'MoneyTest::adds'),
        CoveredLine::of(Path::of('src/Money.php'), 12, 'MoneyTest::adds', 'MoneyTest::subtracts'),
        CoveredLine::of(Path::of('src/Tax.php'), 3, 'TaxTest::rates'),
    )->timedEach(
        TimedTest::of('MoneyTest::adds', 0.25),
        TimedTest::of('MoneyTest::subtracts', 0.5),
        TimedTest::of('TaxTest::rates', 0.0),
    ))->and($read instanceof CoverageFile ? $read->seconds() : 0.0)->toBe(0.75);
});

it('hands each line the tests that cover it, wherever they stand among the map\'s tests', function (): void {
    $at = sharedProject();
    $target = sprintf('%s/shared.coverage.php', $at->root());
    $map = CoverageMap::of(
        CoveredLine::of(Path::of('src/Money.php'), 3, 'MoneyTest::adds'),
        CoveredLine::of(Path::of('src/Money.php'), 4, 'MoneyTest::subtracts'),
        CoveredLine::of(Path::of('src/Tax.php'), 7, 'TaxTest::rounds', 'TaxTest::rates'),
    );

    SharedCoverage::write($map, $at, $target);
    $read = CoverageFile::at($target);

    expect($read instanceof CoverageFile ? $read->map($at)->testsCovering(Path::of('src/Tax.php'), Line::of(7)) : $read)
        ->toEqual(TestIds::of(TestId::of('TaxTest::rounds'), TestId::of('TaxTest::rates')));
});
