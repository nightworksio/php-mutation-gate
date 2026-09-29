<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\LineRate;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('holds a prefix without its trailing separator, and what a line costs under it', function (): void {
    $perLine = Seconds::of(0.5);
    $rate = LineRate::of('src/Http/', $perLine);

    expect($rate->prefix())->toBe('src/Http')
        ->and($rate->perLine())->toBe($perLine);
});

it('covers its path and those below it, and none only starting alike', function (string $path, bool $covered): void {
    expect(LineRate::of('src/Http', Seconds::of(0.5))->covers(Path::of($path)))->toBe($covered);
})->with([
    'itself' => ['src/Http', true],
    'a file below it' => ['src/Http/Kernel.php', true],
    'a sibling that starts alike' => ['src/HttpClient.php', false],
    'a path that ends alike' => ['lib/src/Http', false],
    'a path elsewhere' => ['lib/A.php', false],
]);

it('covers every path with the empty prefix', function (): void {
    expect(LineRate::of('', Seconds::of(0.2))->covers(Path::of('lib/A.php')))->toBeTrue();
});
