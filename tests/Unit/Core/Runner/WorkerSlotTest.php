<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Processes;
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

    expect($variables([...WorkerSlots::of(Processes::of(3), 'run')]))->toBe([
        ['PARATEST' => '1', 'TEST_TOKEN' => '1', 'UNIQUE_TEST_TOKEN' => '1_run', 'LARAVEL_PARALLEL_TESTING' => '1'],
        ['PARATEST' => '1', 'TEST_TOKEN' => '2', 'UNIQUE_TEST_TOKEN' => '2_run', 'LARAVEL_PARALLEL_TESTING' => '1'],
        ['PARATEST' => '1', 'TEST_TOKEN' => '3', 'UNIQUE_TEST_TOKEN' => '3_run', 'LARAVEL_PARALLEL_TESTING' => '1'],
    ])->and($variables([...WorkerSlots::of(Processes::single(), 'run')]))->toBe([[]]);
});
