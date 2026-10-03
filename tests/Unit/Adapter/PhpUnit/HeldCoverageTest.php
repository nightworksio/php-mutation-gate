<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\PhpUnit\HeldCoverage;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestId;

/**
 * A reading that notes each time it is read, answering as given.
 *
 * @param  ArrayObject<int, string>               $reads
 * @return Closure(): (CoverageMap|CannotJudge)
 */
function countedReading(ArrayObject $reads, CoverageMap|CannotJudge $answer): Closure
{
    return static function () use ($reads, $answer): CoverageMap|CannotJudge {
        $reads->append('read');

        return $answer;
    };
}

$map = CoverageMap::empty()->covered(Path::of('src/Money.php'), Line::of(3), TestId::of('T::adds'));

it('reads a map once for every read of the same, and afresh for another or once forgotten', function () use ($map): void {
    $held = new HeldCoverage();
    $reads = new ArrayObject();

    $first = $held->readFrom('whole suite', countedReading($reads, $map));
    $again = $held->readFrom('whole suite', countedReading($reads, CoverageMap::empty()));
    $other = $held->readFrom('a group', countedReading($reads, CoverageMap::empty()));
    $back = $held->readFrom('whole suite', countedReading($reads, CoverageMap::empty()));
    $held->forget();
    $forgotten = $held->readFrom('a group', countedReading($reads, $map));

    expect([$first, $again, $other, $back, $forgotten])->toEqual([$map, $map, CoverageMap::empty(), CoverageMap::empty(), $map])
        ->and(count($reads))->toBe(4);
});

it('holds nothing a reading could not judge, so the next reads again', function () use ($map): void {
    $held = new HeldCoverage();
    $reads = new ArrayObject();

    $failed = $held->readFrom('whole suite', countedReading($reads, CannotJudge::because('The suite failed.')));
    $read = $held->readFrom('whole suite', countedReading($reads, $map));

    expect([$failed, $read])->toEqual([CannotJudge::because('The suite failed.'), $map])
        ->and(count($reads))->toBe(2);
});
