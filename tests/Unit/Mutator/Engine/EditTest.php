<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Mutator\Engine\Edit;

it('marks the lines a change removes and those it adds, leaving out the lines around them', function (
    string $original,
    string $mutated,
    string $changed,
): void {
    expect(Edit::of(Line::of(1), Line::of(1), $original, $mutated)->changed())->toBe($changed);
})->with([
    'its first line' => ["a\nb", "c\nb", "-a\n+c"],
    'a line like the ones before it, added' => ["a\na", "a\na\na", '+a'],
    'a line like the ones after it, removed' => ["a\na\nb", "a\nb", '-a'],
]);
