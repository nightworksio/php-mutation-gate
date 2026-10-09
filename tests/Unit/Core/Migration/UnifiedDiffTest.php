<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Migration\UnifiedDiff;

it('shows nothing of a file that did not change', function (): void {
    expect(UnifiedDiff::between('a.json', "{}\n", "{}\n"))->toBe('');
});

it('shows each change with three unchanged lines on each side, as diff -u counts them', function (): void {
    $before = implode("\n", ['1', '2', '3', '4', '5', 'old', '7', '8', '9', '10', '11', '12', '13', 'gone', '15', '']);
    $after = implode("\n", ['1', '2', '3', '4', '5', 'new', '7', '8', '9', '10', '11', '12', '13', '15', 'added', '']);

    expect(UnifiedDiff::between('mutation-gate.json', $before, $after))->toBe(implode("\n", [
        '--- a/mutation-gate.json',
        '+++ b/mutation-gate.json',
        '@@ -3,7 +3,7 @@',
        ' 3',
        ' 4',
        ' 5',
        '-old',
        '+new',
        ' 7',
        ' 8',
        ' 9',
        '@@ -11,5 +11,5 @@',
        ' 11',
        ' 12',
        ' 13',
        '-gone',
        ' 15',
        '+added',
        '',
    ]));
});

it('joins changes whose unchanged lines touch into one hunk, and shows a file all of whose lines changed', function (): void {
    expect(UnifiedDiff::between('f', "a\nb\nc\nd\n", "a\nB\nc\nD\n"))->toBe("--- a/f\n+++ b/f\n@@ -1,4 +1,4 @@\n a\n-b\n+B\n c\n-d\n+D\n")
        ->and(UnifiedDiff::between('f', 'x', 'y'))->toBe("--- a/f\n+++ b/f\n@@ -1,1 +1,1 @@\n-x\n+y\n");
});

it('joins two changes six unchanged lines apart into one hunk, as diff -u does, and keeps seven apart in two', function (): void {
    $lines = static fn(string ...$each): string => implode("\n", [...$each, '']);
    $six = UnifiedDiff::between('f', $lines('a', '1', '2', '3', '4', '5', '6', 'b'), $lines('A', '1', '2', '3', '4', '5', '6', 'B'));
    $seven = UnifiedDiff::between('f', $lines('a', '1', '2', '3', '4', '5', '6', '7', 'b'), $lines('A', '1', '2', '3', '4', '5', '6', '7', 'B'));

    expect(substr_count($six, '@@ -'))->toBe(1)
        ->and(substr_count($seven, '@@ -'))->toBe(2);
});
