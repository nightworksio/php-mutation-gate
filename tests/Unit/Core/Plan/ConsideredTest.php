<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Plan\Considered;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;

it('considers everything, with no change, no reason and nothing proved or carried, until told otherwise', function (): void {
    $everything = Considered::everything();

    expect($everything->changed())->toEqual(Changes::none())
        ->and($everything->reach())->toEqual(Reasons::of())
        ->and($everything->proved())->toEqual(Units::none())
        ->and($everything->carried())->toEqual(Units::none());
});

it('proves and carries the units it is told, each keeping everything else', function (): void {
    $proved = Units::of(Unit::file(Path::of('src/A.php')));
    $carried = Units::of(Unit::file(Path::of('src/B.php')), Unit::file(Path::of('src/C.php')));
    $changed = Changes::of(Change::modified(Path::of('src/Money.php'), Lines::of(Line::of(3))));
    $reach = Reasons::of(Reason::that('src/Money.php changed.'));
    $both = Considered::everything()->reaching($changed, $reach)->proving($proved)->carrying($carried);

    expect($both->proved())->toBe($proved)
        ->and($both->carried())->toBe($carried)
        ->and($both->carrying($carried)->proved())->toBe($proved)
        ->and($both->proving($proved)->carried())->toBe($carried)
        ->and($both->changed())->toBe($changed)
        ->and($both->reach())->toBe($reach)
        ->and($both->reaching(Changes::none(), Reasons::of())->proved())->toBe($proved);
});
