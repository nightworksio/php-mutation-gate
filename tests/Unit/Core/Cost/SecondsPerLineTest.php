<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\LineRate;
use NightWorksIO\MutationGate\Core\Cost\SecondsPerLine;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('costs every path a fifth of a second a line by default', function (): void {
    expect(SecondsPerLine::standard()->forPath(Path::of('src/Money.php')))->toEqual(Seconds::of(0.2));
});

it('costs a path by the longest prefix it is under, in whatever order', function (string $path, float $seconds): void {
    $perLine = SecondsPerLine::of(
        LineRate::of('src/Http', Seconds::of(0.5)),
        LineRate::of('src', Seconds::of(0.3)),
        LineRate::of('', Seconds::of(0.2)),
    );

    expect($perLine->forPath(Path::of($path)))->toEqual(Seconds::of($seconds));
})->with([
    'under the longest' => ['src/Http/Kernel.php', 0.5],
    'under a shorter one' => ['src/Money.php', 0.3],
    'under only the empty prefix' => ['lib/A.php', 0.2],
]);

it('takes the later of two prefixes alike', function (): void {
    $perLine = SecondsPerLine::of(LineRate::of('src', Seconds::of(0.3)), LineRate::of('src', Seconds::of(0.4)));

    expect($perLine->forPath(Path::of('src/Money.php')))->toEqual(Seconds::of(0.4));
});

it('costs a path under no prefix nothing', function (): void {
    $perLine = SecondsPerLine::of(LineRate::of('src', Seconds::of(0.3)));

    expect($perLine->forPath(Path::of('lib/A.php')))->toEqual(Seconds::of(0.0));
});
