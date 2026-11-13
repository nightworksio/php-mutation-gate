<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Order\Saved;

it('knows the files saved since the round before apart from those the saved tests run', function (): void {
    $saved = Saved::of(Paths::of(Path::of('src/Money.php')), Paths::of(Path::of('src/Clock.php')));

    expect($saved->edits(Path::of('src/Money.php')))->toBeTrue()
        ->and($saved->edits(Path::of('src/Clock.php')))->toBeFalse()
        ->and($saved->retests(Path::of('src/Clock.php')))->toBeTrue()
        ->and($saved->retests(Path::of('src/Money.php')))->toBeFalse();
});

it('holds nothing saved outside a quick round', function (): void {
    expect(Saved::none()->edits(Path::of('src/Money.php')))->toBeFalse()
        ->and(Saved::none()->retests(Path::of('src/Money.php')))->toBeFalse();
});
