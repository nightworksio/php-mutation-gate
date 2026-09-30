<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\GateSecret;

it('names each variable that holds a secret the gate reads', function (): void {
    expect(array_map(static fn(GateSecret $secret): string => $secret->value, GateSecret::cases()))->toBe([
        'OTEL_EXPORTER_OTLP_HEADERS',
        'MUTATION_GATE_SLACK_URL',
        'MUTATION_GATE_DISCORD_URL',
        'MUTATION_GATE_WEBHOOK_URL',
        'MUTATION_GATE_WEBHOOK_SECRET',
    ]);
});
