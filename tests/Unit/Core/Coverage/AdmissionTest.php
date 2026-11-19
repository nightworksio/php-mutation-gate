<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Coverage\Admission;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\MapOutline;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\CoverageReports;

// The outline an admission leaves holds the tests of the map it applies and
// the files each ran, so that the entries' keys, which read neither order, agree.

/**
 * An outline's tests, sorted, and the files each ran.
 *
 * @return array{list<string>, array<string, list<string>>}
 */
function unordered(MapOutline $outline): array
{
    [$tests, $ran] = CoverageReports::outlined($outline);
    sort($tests);
    ksort($ran);

    return [$tests, $ran];
}

it('outlines each report\'s map as it admits it, as the map it admits outlines', function (): void {
    foreach (CoverageReports::admitting() as $name => $directory) {
        $map = CoverageReports::map($directory);
        $admission = CoverageReports::admission($directory, MapOutline::of($map));

        expect(unordered($admission->outlined(MapOutline::of($map))))
            ->toBe(unordered(MapOutline::of($admission->applied($map))), $name);
    }
});

it('names the tests leaving, each with the files it stays on', function (): void {
    expect(Admission::of(['T\A::a' => ['src/A.php' => true]])->staying())->toBe(['T\A::a' => ['src/A.php' => true]]);
});

it('keeps a test leaving on the lines of the files it stays on, timed, and drops one that stays on none', function (): void {
    $map = CoverageMap::of(
        CoveredLine::of(Path::of('src/A.php'), 3, 'T\B::b', 'T\A::a'),
        CoveredLine::of(Path::of('src/B.php'), 4, 'T\A::a', 'T\C::c'),
    )->timedEach(TimedTest::of('T\A::a', 1.0), TimedTest::of('T\B::b', 2.0), TimedTest::of('T\C::c', 3.0));

    expect(Admission::of(['T\A::a' => ['src/B.php' => true], 'T\B::b' => []])->applied($map))->toEqual(CoverageMap::of(
        CoveredLine::of(Path::of('src/A.php'), 3),
        CoveredLine::of(Path::of('src/B.php'), 4, 'T\A::a', 'T\C::c'),
    )->timedEach(TimedTest::of('T\A::a', 1.0), TimedTest::of('T\C::c', 3.0)));
});
