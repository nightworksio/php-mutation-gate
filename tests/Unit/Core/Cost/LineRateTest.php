<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\LineRate;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('holds a prefix without its trailing separator, and what a line costs under it', function (): void {
    $perLine = Seconds::of(0.5);
    $rate = LineRate::of('src/Http/', $perLine);

    expect($rate->prefix())->toEqual(Path::of('src/Http'))
        ->and($rate->written())->toBe('src/Http')
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

it('covers every path with the empty prefix, the root or no prefix at all, and writes it empty', function (): void {
    expect(LineRate::of('', Seconds::of(0.2))->covers(Path::of('lib/A.php')))->toBeTrue()
        ->and(LineRate::of('.', Seconds::of(0.2))->covers(Path::of('lib/A.php')))->toBeTrue()
        ->and(LineRate::everywhere(Seconds::of(0.2))->covers(Path::of('lib/A.php')))->toBeTrue()
        ->and(LineRate::everywhere(Seconds::of(0.2))->written())->toBe('')
        ->and(LineRate::of('.', Seconds::of(0.2))->written())->toBe('');
});

it('is narrower the longer its prefix, and every path the least narrow', function (): void {
    expect(LineRate::everywhere(Seconds::of(0.2))->narrowness())->toBe(0)
        ->and(LineRate::of('a', Seconds::of(0.2))->narrowness())->toBe(1)
        ->and(LineRate::of('src/Http', Seconds::of(0.2))->narrowness())->toBe(8);
});
