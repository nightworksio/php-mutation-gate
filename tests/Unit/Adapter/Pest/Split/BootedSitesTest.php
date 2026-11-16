<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\BootedSites;
use NightWorksIO\MutationGate\Adapter\Pest\Split\ChildLimit;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamLine;
use NightWorksIO\MutationGate\Adapter\Pest\Split\StreamPlan;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

afterEach(function (): void {
    Scratch::sweep();
});

it('leaves the mutant of each planned site that ran while the stream booted, written once', function (): void {
    $results = sprintf('%s/stream.jsonl', Scratch::directory());
    $sites = new BootedSites(new StreamPlan($results, ['f1' => [1 => ['m1', '', []], 2 => ['m2', '', []]]], [], [], 1, new ChildLimit(0.0, 0.0, 0.0)));

    $before = $sites->areBooting();
    $sites->booting(booting: true);
    $booting = $sites->areBooting();
    $sites->ran('f1', 1);
    $sites->ran('f1', 1);
    $sites->ran('f1', 9);
    $sites->ran('f9', 1);
    $sites->booting(booting: false);

    expect([$before, $booting, $sites->areBooting()])->toBe([false, true, false])
        ->and([$sites->holds('m1'), $sites->holds('m2')])->toBe([true, false])
        ->and(file_get_contents($results))->toBe(StreamLine::booted('m1'));
});
