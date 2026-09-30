<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Nearest;

it('suggests the known key a misspelt one most likely meant', function (
    string $key,
    string $near,
    string ...$known,
): void {
    expect(Nearest::to($key, array_values($known)))->toBe($near);
})->with([
    'the same key in another case' => ['newcode', 'newCode', 'uncovered', 'newCode', 'trees'],
    'two edits away' => ['tress', 'trees', 'runner', 'trees'],
    'a third of a long key away' => ['prePshBdgt', 'prePushBudget', 'watchBudget', 'prePushBudget'],
    'the first of two equally near' => ['max', 'may', 'may', 'mix'],
    'the nearer of two, whatever their order' => ['maxx', 'max', 'mix', 'max'],
]);

it('suggests nothing when no known key is close', function (string $key, string ...$known): void {
    expect(Nearest::to($key, array_values($known)))->toBe('');
})->with([
    'three edits from a short key' => ['abc', 'ci'],
    'more than a third of a long key away' => ['preBudgetPushed', 'prePushBudget'],
    'no key known at all' => ['workers'],
]);
