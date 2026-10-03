<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Nearest;
use NightWorksIO\MutationGate\Core\Config\Definition\NothingNear;

it('suggests the known key a misspelt one most likely meant', function (
    string $key,
    string $near,
    string ...$known,
): void {
    expect(Nearest::to($key, array_values($known)))->toBe($near);
})->with([
    'the same key in another case' => ['newcode', 'newCode', 'uncovered', 'newCode', 'trees'],
    'one edit from a short key' => ['tress', 'trees', 'runner', 'trees'],
    'one edit from a key too short for a third of it' => ['co', 'ci', 'trees', 'ci'],
    'two edits from a six-letter key' => ['abcxyf', 'abcdef', 'abcdef'],
    'a third of a long key away' => ['prePshBdgt', 'prePushBudget', 'watchBudget', 'prePushBudget'],
    'the first of two equally near' => ['max', 'may', 'may', 'mix'],
    'the nearer of two, whatever their order' => ['abcdef', 'abcdxf', 'abxyef', 'abcdxf'],
]);

it('suggests nothing when no known key is close', function (string $key, string ...$known): void {
    expect(Nearest::to($key, array_values($known)))->toEqual(NothingNear::of());
})->with([
    'three edits from a short key' => ['abc', 'ci'],
    'two edits from a five-letter key' => ['abcde', 'abxye'],
    'more than a third of a long key away' => ['preBudgetPushed', 'prePushBudget'],
    'no key known at all' => ['workers'],
]);
