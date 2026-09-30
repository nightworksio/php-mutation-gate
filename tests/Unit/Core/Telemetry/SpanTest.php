<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Cost\Phase;
use NightWorksIO\MutationGate\Core\Telemetry\Span;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Support\Moment;

it('holds its name, its id and its parent\'s, when it ran and its attributes', function (): void {
    $phase = Phase::of(Moment::at('2026-09-30T11:50:00Z'), Seconds::of(40.0));
    $span = Span::of('mutate', 'a1b2c3d4e5f60718', '0123456789abcdef', $phase, ['mutation_gate.shard' => 2]);

    expect($span->name())->toBe('mutate')
        ->and($span->id())->toBe('a1b2c3d4e5f60718')
        ->and($span->parent())->toBe('0123456789abcdef')
        ->and($span->phase())->toBe($phase)
        ->and($span->attributes())->toBe(['mutation_gate.shard' => 2]);
});
