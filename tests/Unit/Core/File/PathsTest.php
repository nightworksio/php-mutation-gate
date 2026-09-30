<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Tests\Support\Growth;

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

it('builds and searches paths in time linear in their number', function (): void {
    $held = static function (int $size): Closure {
        $each = array_map(static fn(int $at): Path => Path::of(sprintf('src/F%d.php', $at)), range(1, $size));

        return static function () use ($each): Paths {
            $held = Paths::of(...$each, ...$each);

            foreach ($each as $path) {
                $held = $held->has($path) ? $held : Paths::none();
            }

            return $held;
        };
    };

    expect($held(10)())->toHaveCount(10)
        ->and(Growth::of(625, $held))->toBeLessThan(Growth::LINEAR);
});
