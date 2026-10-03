<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Ci\Delivery;
use NightWorksIO\MutationGate\Core\Ci\Publication;

it('says what a CI plan publishes, where, and how it goes there', function (): void {
    $parts = static fn(Publication $publication): array => [
        $publication->text(),
        $publication->to(),
        $publication->delivery(),
    ];

    expect($parts(Publication::printed('steps')))->toBe(['steps', 'php://output', Delivery::Printed])
        ->and($parts(Publication::written('.mutation-gate/pipeline.yml', 'jobs')))
        ->toBe(['jobs', '.mutation-gate/pipeline.yml', Delivery::Written])
        ->and($parts(Publication::appended('/tmp/output', "shards=[]\n")))
        ->toBe(["shards=[]\n", '/tmp/output', Delivery::Appended]);
});
