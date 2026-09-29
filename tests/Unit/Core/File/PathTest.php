<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;

it('spells a path with forward slashes', function (): void {
    expect(Path::of('src\\Core\\Money.php')->value())->toBe('src/Core/Money.php');
});

it('drops empty and current-directory segments', function (): void {
    expect(Path::of('./src//Core/./Money.php')->value())->toBe('src/Core/Money.php');
});

it('drops a trailing separator', function (): void {
    expect(Path::of('src/Core/')->value())->toBe('src/Core');
});

it('spells the root as a dot', function (string $root): void {
    expect(Path::of($root)->value())->toBe('.');
})->with(['', '.', './', '/./']);

it('names the root without spelling it', function (): void {
    expect(Path::root()->value())->toBe('.');
});

it('keeps an absolute path absolute', function (): void {
    expect(Path::of('/tmp//gate/')->value())->toBe('/tmp/gate');
});

it('equals a path spelt differently that names the same place', function (): void {
    expect(Path::of('src/')->equals(Path::of('./src')))->toBeTrue()
        ->and(Path::of('src')->equals(Path::of('tests')))->toBeFalse();
});
