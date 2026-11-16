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

it('marks the lines a change in the middle of the code touches, counting on into the lines after them', function (
    string $original,
    int $at,
    int $length,
    string $replacement,
    string $changed,
): void {
    expect(Edit::spliced(Line::of(1), Line::of(1), $original, $at, $length, $replacement)->changed())->toBe($changed);
})->with([
    'a word in a line' => ["a\nbc\nd", 3, 1, 'x', "-bc\n+bx"],
    'a line like the one after it, added' => ["a\nb\nb\nc", 2, 0, "b\n", '+b'],
    'a line like the one after it, removed' => ["a\nb\nb\nc", 2, 2, '', '-b'],
    'the last line, ended by a newline' => ["a\nb\n", 2, 1, 'c', "-b\n+c"],
    'nothing at all' => ["a\nb", 2, 1, 'b', ''],
    'the first line' => ['ab', 1, 1, 'c', "-ab\n+ac"],
    'a newline, joining two lines' => ["a\nb\nc", 1, 1, '', "-a\n-b\n+ab"],
    'lines added that the lines after them repeat' => ["\na\n\n\n\n", 6, 0, "\n\n\na", "+\n+\n+a"],
    'empty lines added around a word the lines before them hold' => ["\nacc\n", 4, 0, "\n\n\n\nb\n", "+\n+\n+b\n+\n+"],
    'lines removed that the lines after them repeat' => ["bc\n\n\nacc\n\n\nb\n\n", 2, 8, '', "-\n-\n-acc\n-\n-"],
    'lines removed, the first like the lines after them' => ["\n\n\n\nc\na\n\ncb", 1, 6, '', "-\n-c\n-a\n-"],
    'lines added that the lines after them repeat in part' => ["aa\nc\n\na\n\n\nc", 3, 0, "c\n\na\nc", "+cc\n+\n+a"],
]);

it('puts the code together with the change in place of the bytes it replaces', function (): void {
    $edit = Edit::spliced(Line::of(2), Line::of(2), "a\nbc\nd", 2, 1, 'xy');

    expect($edit->mutated())->toBe("a\nxyc\nd")
        ->and($edit->start())->toEqual(Line::of(2))
        ->and($edit->end())->toEqual(Line::of(2))
        ->and(Edit::of(Line::of(1), Line::of(1), 'ab', 'cd')->mutated())->toBe('cd');
});

it('tells whether the code with the change in place is the code as it was', function (): void {
    expect(Edit::spliced(Line::of(1), Line::of(1), 'abc', 1, 1, 'b')->leavesCodeAsItWas())->toBeTrue()
        ->and(Edit::spliced(Line::of(1), Line::of(1), 'abc', 1, 1, 'x')->leavesCodeAsItWas())->toBeFalse()
        ->and(Edit::spliced(Line::of(1), Line::of(1), 'abc', 1, 0, '')->leavesCodeAsItWas())->toBeTrue()
        ->and(Edit::of(Line::of(1), Line::of(1), 'abc', 'abc')->leavesCodeAsItWas())->toBeTrue();
});
