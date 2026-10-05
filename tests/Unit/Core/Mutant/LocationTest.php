<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Unreported;

it('is a file and the lines a mutant starts and ends on', function (): void {
    $location = Location::of(Path::of('src/Money.php'), Line::of(42), Line::of(44));

    expect($location->file()->value())->toBe('src/Money.php')
        ->and($location->start()->number())->toBe(42)
        ->and($location->end())->toEqual(Line::of(44));
});

it('may not know the line a mutant ends on', function (): void {
    expect(Location::of(Path::of('src/Money.php'), Line::of(42), Unreported::line())->end())->toEqual(Unreported::line());
});

it('spans to the line it ends on, or the one it starts on where the end is not known', function (): void {
    expect(Location::of(Path::of('src/Money.php'), Line::of(42), Line::of(44))->last())->toEqual(Line::of(44))
        ->and(Location::of(Path::of('src/Money.php'), Line::of(42), Unreported::line())->last())->toEqual(Line::of(42));
});
