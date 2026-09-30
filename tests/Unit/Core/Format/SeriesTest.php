<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Format\Series;

it('lists words as a sentence does, with and', function (string $said, string ...$words): void {
    expect(Series::and(...$words))->toBe($said);
})->with([
    'none' => [''],
    'one' => ['github', 'github'],
    'two' => ['github and gitlab', 'github', 'gitlab'],
    'several' => ['github, gitlab and azure', 'github', 'gitlab', 'azure'],
]);

it('lists words as a sentence does, with or', function (string $said, string ...$words): void {
    expect(Series::or(...$words))->toBe($said);
})->with([
    'one' => ['"auto"', '"auto"'],
    'two' => ['"auto" or "never"', '"auto"', '"never"'],
    'several' => ['"a", "b" or "c"', '"a"', '"b"', '"c"'],
]);
