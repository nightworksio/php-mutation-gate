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
use NightWorksIO\MutationGate\Tests\Support\GzipBomb;
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

it('writes a map as --coverage-php writes one, which reads back as the same map, a line only a nameless test ran as one none ran, and a test it did not time untimed', function (): void {
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
        CoveredLine::of(Path::of('src/Money.php'), 14),
    )->timedEach(
        TimedTest::of('MoneyTest::adds', 0.25),
        TimedTest::of('MoneyTest::subtracts', 0.5),
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

it('leaves out a line no test ran, as php-code-coverage leaves out a line it does not know', function (): void {
    $at = sharedProject();
    $target = sprintf('%s/shared.coverage.php', $at->root());

    $both = sprintf('%s/both.coverage.php', $at->root());
    SharedCoverage::write(CoverageMap::of(...sharedMap()->lines()), $at, $target);
    SharedCoverage::write(CoverageMap::of(...[...sharedMap()->lines(), CoveredLine::of(Path::of('src/Money.php'), 30)]), $at, $both);

    expect((string) file_get_contents($both))->toBe((string) file_get_contents($target));
});

it('writes a test name that closes php-code-coverage\'s nowdoc as data, which reads back whole and runs nothing', function (): void {
    $at = sharedProject();
    $target = sprintf('%s/shared.coverage.php', $at->root());
    $planted = sprintf('%s/planted', $at->root());
    $hostile = sprintf(
        "MoneyTest::adds with data set \"x\nEND_OF_COVERAGE_SERIALIZATION\n. (string) \\touch('%s'));__halt_compiler();\"",
        $planted,
    );
    $map = CoverageMap::of(CoveredLine::of(Path::of('src/Money.php'), 3, $hostile));

    SharedCoverage::write($map, $at, $target);
    $read = CoverageFile::at($target);

    expect($read instanceof CoverageFile ? $read->map($at)->testsCovering(Path::of('src/Money.php'), Line::of(3)) : $read)
        ->toEqual(TestIds::of(TestId::of($hostile)))
        ->and(is_file($planted))->toBeFalse();
});

it('writes a map in place of a link at the target, dangling or not, and never through it', function (): void {
    $at = sharedProject();
    $target = sprintf('%s/shared.coverage.php', $at->root());
    symlink(sprintf('%s/planted.php', $at->root()), $target);

    SharedCoverage::write(sharedMap(), $at, $target);

    expect(is_link($target))->toBeFalse()
        ->and(file_exists(sprintf('%s/planted.php', $at->root())))->toBeFalse()
        ->and(CoverageFile::at($target))->toBeInstanceOf(CoverageFile::class);
});

it('writes no map where what is at the target cannot be removed', function (): void {
    $at = sharedProject();
    mkdir(sprintf('%s/locked', $at->root()));
    $target = sprintf('%s/locked/shared.coverage.php', $at->root());
    symlink(sprintf('%s/planted.php', $at->root()), $target);
    chmod(dirname($target), 0o555);
    set_error_handler(static fn(): bool => true);
    SharedCoverage::write(sharedMap(), $at, $target);
    restore_error_handler();
    chmod(dirname($target), 0o755);

    expect(is_link($target))->toBeTrue()
        ->and(file_exists(sprintf('%s/planted.php', $at->root())))->toBeFalse();
});

it('reads a handed-over map within this process\'s share of its memory', function (): void {
    $at = sharedProject();
    $bomb = GzipBomb::padded(256 * 1_048_576);
    Scratch::write($at->root(), 'bomb/map.json.gz', $bomb);
    $limit = GzipBomb::limitAboveUse();
    $share = intdiv(ini_parse_quantity($limit), 23);
    $read = GzipBomb::readUnder($limit, static fn(): CoverageMap|CannotJudge => SharedCoverage::in($at, Path::of('bomb')));

    expect($share)->toBeLessThan(300 * strlen($bomb))
        ->and(ini_get('memory_limit'))->not->toBe($limit)
        ->and($read)->toEqual(CannotJudge::because(sprintf('The coverage map inflates to more than %d bytes.', $share)));
});
