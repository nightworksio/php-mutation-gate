<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Git\Diff;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Tests\Support\Stopwatch;

it('reads every entry of a NUL-separated name-status with the lines each gained', function (): void {
    $status = "M\0src/A.php\0R100\0src/C.php\0src/D.php\0A\0src/G.php\0D\0src/B.php\0T\0src/L.php\0R087\0src/E.php\0src/F.php\0";
    $lines = [
        'src/A.php' => Lines::of(Line::of(2)),
        'src/G.php' => Lines::of(Line::of(1), Line::of(2)),
        'src/F.php' => Lines::of(Line::of(4)),
        'src/E.php' => Lines::of(Line::of(9)),
    ];

    expect(Diff::changes($status, $lines))->toEqual(Changes::of(
        Change::modified(Path::of('src/A.php'), Lines::of(Line::of(2))),
        Change::renamed(Path::of('src/C.php'), Path::of('src/D.php'), Lines::none()),
        Change::added(Path::of('src/G.php'), Lines::of(Line::of(1), Line::of(2))),
        Change::deleted(Path::of('src/B.php')),
        Change::modified(Path::of('src/L.php'), Lines::none()),
        Change::renamed(Path::of('src/E.php'), Path::of('src/F.php'), Lines::of(Line::of(4))),
    ));
});

it('reads nothing from a name-status that lists nothing', function (): void {
    expect(Diff::changes('', []))->toEqual(Changes::none());
});

it('reads the lines each file gained on its new side from a patch with no context', function (): void {
    $patch = <<<'PATCH'
        diff --git a/src/A.php b/src/A.php
        index 1111111..2222222 100644
        --- a/src/A.php
        +++ b/src/A.php
        @@ -2 +2 @@ final class A
        -    old
        +    new
        @@ -10,0 +11,3 @@ final class A
        +    one
        +    two
        +    three
        diff --git a/src/B.php b/src/B.php
        --- a/src/B.php
        +++ b/src/B.php
        @@ -4,2 +3,0 @@
        -    gone
        -    gone
        diff --git a/src/Gone.php b/src/Gone.php
        --- a/src/Gone.php
        +++ /dev/null
        @@ -1,2 +0,0 @@
        -<?php
        -gone
        diff --git "a/src/say \"hi\".php" "b/src/say \"hi\".php"
        --- "a/src/say \"hi\".php"
        +++ "b/src/say \"hi\".php"
        @@ -1 +1,2 @@
        -<?php
        +<?php
        +// hi
        PATCH;

    $lines = Diff::lines($patch);

    expect($lines['src/A.php'])->toEqual(Lines::of(Line::of(2), Line::of(11), Line::of(12), Line::of(13)))
        ->and($lines['src/B.php'])->toEqual(Lines::none())
        ->and($lines['src/say "hi".php'])->toEqual(Lines::of(Line::of(1), Line::of(2)))
        ->and(array_keys($lines))->toBe(['src/A.php', 'src/B.php', 'src/say "hi".php']);
});

it('reads every line of a new file as gained', function (string $text, int $count): void {
    expect(Diff::whole($text))->toEqual(Lines::of(...array_map(Line::of(...), $count === 0 ? [] : range(1, $count))));
})->with([
    'nothing' => ['', 0],
    'one line with no break' => ['<?php', 1],
    'lines with a break at the end' => ["<?php\nreturn 1;\n", 2],
    'lines with none at the end' => ["<?php\nreturn 1;", 2],
    'an empty line' => ["\n", 1],
]);

it('reads a new file and a hunk of tens of thousands of lines in linear time', function (): void {
    $text = str_repeat("line\n", 20_000);
    $patch = sprintf("diff --git a/src/A.php b/src/A.php\n--- a/src/A.php\n+++ b/src/A.php\n@@ -0,0 +1,20000 @@\n%s", str_repeat("+line\n", 20_000));
    $whole = Lines::none();
    $gained = [];

    $seconds = Stopwatch::seconds(static function () use ($text, $patch, &$whole, &$gained): void {
        $whole = Diff::whole($text);
        $gained = Diff::lines($patch);
    });

    expect($whole)->toHaveCount(20_000)
        ->and($gained['src/A.php'])->toHaveCount(20_000)
        ->and($gained['src/A.php']->has(Line::of(20_000)))->toBeTrue()
        ->and($seconds)->toBeLessThan(Stopwatch::BOUND);
});
