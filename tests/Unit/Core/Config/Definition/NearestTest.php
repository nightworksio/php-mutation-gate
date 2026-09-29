<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Config\Definition\Nearest;

it('suggests the known key a misspelt one most likely meant', function (string $key, array $known, string $near): void {
    expect(Nearest::to($key, $known))->toBe($near);
})->with([
    'the same key in another case' => ['newcode', ['uncovered', 'newCode', 'trees'], 'newCode'],
    'two edits away' => ['tress', ['runner', 'trees'], 'trees'],
    'a third of a long key away' => ['prePshBdgt', ['watchBudget', 'prePushBudget'], 'prePushBudget'],
    'the first of two equally near' => ['max', ['may', 'mix'], 'may'],
    'the nearer of two, whatever their order' => ['secnds', ['max', 'seconds'], 'seconds'],
]);

it('suggests nothing when no known key is close', function (string $key, array $known): void {
    expect(Nearest::to($key, $known))->toBe('');
})->with([
    'three edits from a short key' => ['abc', ['ci']],
    'more than a third of a long key away' => ['preBudgetPushed', ['prePushBudget']],
    'no key known at all' => ['workers', []],
]);
