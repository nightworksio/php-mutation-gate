<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Heartbeat;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Killers;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Loaded;
use NightWorksIO\MutationGate\Adapter\Pest\Silence;
use NightWorksIO\MutationGate\Tests\Support\Beats;
use NightWorksIO\MutationGate\Tests\Support\PhpUnitEvents;
use NightWorksIO\MutationGate\Tests\Support\Scratch;
use PHPUnit\Event\Facade;

afterEach(function (): void {
    // Killers logs this process's errors to the mutant's own file, as it does in a mutant's own process.
    ini_restore('error_log');
    ini_restore('log_errors');
    Scratch::sweep();
});

it('beats once as a mutant\'s own process begins its tests, and once as each finishes', function (): void {
    $beats = new Beats();
    $events = new Facade();
    Killers::listening(
        sprintf('%s/results.jsonl', Scratch::directory()),
        '/tmp/mutations/abc',
        $events,
        original: false,
        loaded: Loaded::of([]),
        heartbeat: $beats->heartbeat(),
    );

    PhpUnitEvents::executionStarted($events);
    $started = $beats->kept();
    PhpUnitEvents::finished($events);
    PhpUnitEvents::finished($events);

    expect($started)->toBe(Silence::BEAT)
        ->and($beats->kept())->toBe(sprintf('%1$s%1$s%1$s', Silence::BEAT));
});

it('beats on the process\'s error output', function (): void {
    expect(Heartbeat::onErrorOutput()->beat(...))->not->toThrow(Throwable::class);
});
