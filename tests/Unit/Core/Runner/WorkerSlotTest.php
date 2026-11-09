<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlot;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlots;

it('tells a process it is a parallel worker, its place\'s number and a token no other place of any run shares, as paratest tells its workers', function (): void {
    expect(iterator_to_array(WorkerSlot::of(3, 'a1b2')->variables(), preserve_keys: true))->toBe([
        'PARATEST' => '1',
        'TEST_TOKEN' => '3',
        'UNIQUE_TEST_TOKEN' => '3_a1b2',
        'LARAVEL_PARALLEL_TESTING' => '1',
    ])
        ->and(iterator_to_array(WorkerSlot::alone()->variables(), preserve_keys: true))->toBe([]);
});

it('gives each of several processes a place numbered from one, and one process a place that tells it nothing', function (): void {
    $variables = static function (array $slots): array {
        $told = [];

        foreach ($slots as $slot) {
            $told[] = $slot instanceof WorkerSlot ? iterator_to_array($slot->variables(), preserve_keys: true) : [];
        }

        return $told;
    };

    expect($variables([...WorkerSlots::of(ProcessCount::of(3), 'run')]))->toBe([
        ['PARATEST' => '1', 'TEST_TOKEN' => '1', 'UNIQUE_TEST_TOKEN' => '1_run', 'LARAVEL_PARALLEL_TESTING' => '1'],
        ['PARATEST' => '1', 'TEST_TOKEN' => '2', 'UNIQUE_TEST_TOKEN' => '2_run', 'LARAVEL_PARALLEL_TESTING' => '1'],
        ['PARATEST' => '1', 'TEST_TOKEN' => '3', 'UNIQUE_TEST_TOKEN' => '3_run', 'LARAVEL_PARALLEL_TESTING' => '1'],
    ])->and($variables([...WorkerSlots::of(ProcessCount::single(), 'run')]))->toBe([[]]);
});

it('gives several processes side by side places that tell them nothing, as one process alone is told', function (): void {
    $slots = [...WorkerSlots::untold(2)];

    expect($slots)->toHaveCount(2)
        ->and(array_map(static fn(WorkerSlot $slot): array => iterator_to_array($slot->variables(), preserve_keys: true), $slots))
        ->toBe([[], []]);
});

it('numbers places after those a run beside them holds, so no two processes at once share a token, even one alone', function (): void {
    $tokens = static fn(WorkerSlots $slots): array => array_map(
        static fn(WorkerSlot $slot): ?string => iterator_to_array($slot->variables(), preserve_keys: true)['TEST_TOKEN'] ?? null,
        [...$slots],
    );

    expect($tokens(WorkerSlots::numberedAfter(2, ProcessCount::of(3), 'run')))->toBe(['3', '4', '5'])
        ->and($tokens(WorkerSlots::numberedAfter(3, ProcessCount::single(), 'run')))->toBe(['4'])
        ->and($tokens(WorkerSlots::numberedAfter(0, ProcessCount::of(2), 'run')))->toBe(['1', '2']);
});
