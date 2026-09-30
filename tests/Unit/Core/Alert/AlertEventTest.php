<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Alert\AlertEvent;

it('names each event as the webhook writes it, and says it in words', function (): void {
    expect(array_map(static fn(AlertEvent $event): string => $event->value, AlertEvent::cases()))
        ->toBe(['failed', 'cannot-judge', 'recovered', 'floor-lowered'])
        ->and(array_map(static fn(AlertEvent $event): string => $event->said(), AlertEvent::cases()))
        ->toBe(['failed', 'cannot judge', 'recovered', 'floor lowered']);
});
