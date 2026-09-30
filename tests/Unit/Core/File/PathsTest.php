<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Tests\Support\Stopwatch;

it('holds nothing to begin with', function (): void {
    expect(Paths::none())->toHaveCount(0);
});

it('keeps each path once, in the order it came', function (): void {
    $paths = Paths::of(Path::of('src/B.php'), Path::of('src/A.php'), Path::of('./src/B.php'));

    expect(array_map(static fn(Path $path): string => $path->value(), iterator_to_array($paths, preserve_keys: true)))->toBe(['src/B.php', 'src/A.php'])
        ->and($paths)->toHaveCount(2);
});

it('adds a path it does not hold, and leaves itself alone for one it does', function (): void {
    $paths = Paths::of(Path::of('src/A.php'));

    expect($paths->with(Path::of('src/A.php')))->toBe($paths)
        ->and($paths->with(Path::of('src/B.php')))->toHaveCount(2)
        ->and($paths)->toHaveCount(1);
});

it('says whether it holds a path', function (): void {
    $paths = Paths::of(Path::of('src/A.php'));

    expect($paths->has(Path::of('src/A.php')))->toBeTrue()
        ->and($paths->has(Path::of('src/B.php')))->toBeFalse();
});

it('builds and searches thousands of paths in linear time', function (): void {
    $each = array_map(static fn(int $at): Path => Path::of(sprintf('src/F%d.php', $at)), range(1, 5000));
    $held = Paths::none();

    $seconds = Stopwatch::seconds(static function () use ($each, &$held): void {
        $held = Paths::of(...$each, ...$each);

        foreach ($each as $path) {
            $held = $held->has($path) ? $held : Paths::none();
        }
    });

    expect($held)->toHaveCount(5000)
        ->and($seconds)->toBeLessThan(Stopwatch::BOUND);
});
