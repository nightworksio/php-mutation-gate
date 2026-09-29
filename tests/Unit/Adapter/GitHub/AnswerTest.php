<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\GitHub\Answer;

$answer = static fn(): Answer => Answer::of([
    'sha' => 'abc',
    'total_commits' => 3,
    'commit' => ['tree' => ['sha' => 'def']],
    'commits' => [['sha' => 'one'], 'not an object', ['sha' => 'two']],
    'merged_at' => null,
    'count' => '3',
]);

it('reads the text at a path of keys', function () use ($answer): void {
    expect($answer()->text('sha'))->toBe('abc')
        ->and($answer()->text('commit', 'tree', 'sha'))->toBe('def');
});

it('reads as empty the text of a field that is missing or not text', function () use ($answer): void {
    expect($answer()->text('missing'))->toBe('')
        ->and($answer()->text('commit', 'missing', 'sha'))->toBe('')
        ->and($answer()->text('merged_at'))->toBe('')
        ->and($answer()->text('total_commits'))->toBe('')
        ->and($answer()->text('sha', 'deeper'))->toBe('');
});

it('reads the number at a key, and 0 for a field that is missing or not a number', function () use ($answer): void {
    expect($answer()->number('total_commits'))->toBe(3)
        ->and($answer()->number('count'))->toBe(0)
        ->and($answer()->number('missing'))->toBe(0);
});

it('reads the objects of a list, leaving out what is not one', function () use ($answer): void {
    $items = $answer()->items('commits');

    expect($items)->toHaveCount(2)
        ->and($items[0]->text('sha'))->toBe('one')
        ->and($items[1]->text('sha'))->toBe('two')
        ->and($answer()->items('missing'))->toBe([])
        ->and($answer()->items('sha'))->toBe([]);
});

it('reads the objects of an answer that is a list', function (): void {
    $items = Answer::of([['number' => 12], ['number' => 13]])->items();

    expect($items)->toHaveCount(2)
        ->and($items[1]->number('number'))->toBe(13);
});
