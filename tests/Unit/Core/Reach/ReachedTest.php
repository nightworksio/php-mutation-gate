<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Reach\Reached;

$reached = static fn(): Reached => Reached::none()
    ->and(Paths::of(Path::of('src/Http/Kernel.php'), Path::of('123')))
    ->and(Paths::of(Path::of('/abs/lib/A.php'), Path::of('src/Http/Kernel.php')));

it('holds nothing to begin with', function (): void {
    expect(Reached::none()->paths())->toEqual(Paths::none())
        ->and(Reached::none()->anyWithin(Path::root()))->toBeFalse()
        ->and(Reached::none()->anyAround(Path::of('src/Money.php')))->toBeFalse();
});

it('holds each path once, in the order it was reached', function () use ($reached): void {
    expect($reached()->paths())->toEqual(Paths::of(Path::of('src/Http/Kernel.php'), Path::of('123'), Path::of('/abs/lib/A.php')));
});

it('says whether any path it holds is a directory or inside it', function (string $directory, bool $within) use ($reached): void {
    expect($reached()->anyWithin(Path::of($directory)))->toBe($within);
})->with([
    'the file itself' => ['src/Http/Kernel.php', true],
    'its directory' => ['src/Http', true],
    'a directory above that' => ['src', true],
    'the root' => ['.', true],
    'a path of digits' => ['123', true],
    'a directory of an absolute path' => ['/abs/lib', true],
    'a sibling' => ['src/Money.php', false],
    'a directory spelt as a prefix' => ['src/Ht', false],
    'a file inside the file' => ['src/Http/Kernel.php/x', false],
]);

it('says whether a path is one it holds or inside one', function (string $path, bool $around): void {
    $trees = Reached::none()->and(Paths::of(Path::of('packages/money/src'), Path::of('123')));

    expect($trees->anyAround(Path::of($path)))->toBe($around);
})->with([
    'the tree itself' => ['packages/money/src', true],
    'a file inside it' => ['packages/money/src/Money.php', true],
    'a path of digits' => ['123', true],
    'inside a path of digits' => ['123/A.php', true],
    'its package' => ['packages/money', false],
    'a sibling spelt with it as a prefix' => ['packages/money/srcs/A.php', false],
]);

it('holds every path inside the root', function (): void {
    expect(Reached::none()->and(Paths::of(Path::root()))->anyAround(Path::of('anything/at/all.php')))->toBeTrue();
});

it('leaves what it came from as it was', function (): void {
    $none = Reached::none();
    $none->and(Paths::of(Path::of('src/A.php')));

    expect($none->paths())->toHaveCount(0)
        ->and($none->anyWithin(Path::of('src')))->toBeFalse();
});
