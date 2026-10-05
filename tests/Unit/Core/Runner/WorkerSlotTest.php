<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Processes;
use NightWorksIO\MutationGate\Core\Runner\WorkerSlot;

it('tells a process its place\'s number and a token no other place of any run shares, as paratest tells its workers', function (): void {
    expect(WorkerSlot::of(3, 'a1b2')->variables())->toBe(['TEST_TOKEN' => '3', 'UNIQUE_TEST_TOKEN' => '3_a1b2'])
        ->and(WorkerSlot::alone()->variables())->toBe([]);
});

it('gives each of several processes a place numbered from one, and one process a place that tells it nothing', function (): void {
    $variables = static function (array $slots): array {
        $told = [];

        foreach ($slots as $slot) {
            $told[] = $slot instanceof WorkerSlot ? $slot->variables() : [];
        }

        return $told;
    };

    expect($variables(WorkerSlot::eachOf(Processes::of(3), 'run')))->toBe([
        ['TEST_TOKEN' => '1', 'UNIQUE_TEST_TOKEN' => '1_run'],
        ['TEST_TOKEN' => '2', 'UNIQUE_TEST_TOKEN' => '2_run'],
        ['TEST_TOKEN' => '3', 'UNIQUE_TEST_TOKEN' => '3_run'],
    ])->and($variables(WorkerSlot::eachOf(Processes::single(), 'run')))->toBe([[]]);
});
