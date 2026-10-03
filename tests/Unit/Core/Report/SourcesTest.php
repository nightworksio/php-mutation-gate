<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Report\Sources;

it('holds what each file it read holds, by its path', function (): void {
    $sources = Sources::none()
        ->with(Path::of('src/Money.php'), Contents::of('<?php // money'))
        ->with(Path::of('./src/Order.php'), Contents::of('<?php // order'));

    expect($sources->has(Path::of('src/Money.php')))->toBeTrue()
        ->and($sources->has(Path::of('src/Order.php')))->toBeTrue()
        ->and($sources->of(Path::of('src/Money.php')))->toEqual(Contents::of('<?php // money'))
        ->and($sources->of(Path::of('src/Order.php')))->toEqual(Contents::of('<?php // order'));
});

it('holds nothing for a file it did not read', function (): void {
    expect(Sources::none()->has(Path::of('src/Money.php')))->toBeFalse()
        ->and(Sources::none()->of(Path::of('src/Money.php')))->toEqual(Contents::of(''));
});

it('keeps what a file holds once it is read again', function (): void {
    $sources = Sources::none()
        ->with(Path::of('src/Money.php'), Contents::of('first'))
        ->with(Path::of('src/Money.php'), Contents::of('second'));

    expect($sources->of(Path::of('src/Money.php')))->toEqual(Contents::of('second'));
});
