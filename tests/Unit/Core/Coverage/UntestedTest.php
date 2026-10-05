<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\Untested;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;

it('keeps the changed lines the map holds as executable lines no test ran, and leaves out a file with none', function (): void {
    $money = Path::of('src/Money.php');
    $tax = Path::of('src/Tax.php');
    $map = CoverageMap::of(
        CoveredLine::of($money, 4),
        CoveredLine::of($money, 5, 'MoneyTest::adds'),
        CoveredLine::of($money, 9),
        CoveredLine::of($tax, 2, 'TaxTest::rates'),
    );
    $changed = Changes::of(
        Change::modified($money, Lines::of(Line::of(3), Line::of(4), Line::of(5), Line::of(9))),
        Change::added($tax, Lines::of(Line::of(1), Line::of(2))),
    );

    expect(Untested::of($changed, $map))->toEqual(Changes::of(Change::modified($money, Lines::of(Line::of(4), Line::of(9)))))
        ->and(Untested::of(Changes::none(), $map))->toEqual(Changes::none())
        ->and(Untested::of($changed, CoverageMap::empty()))->toEqual(Changes::none());
});
