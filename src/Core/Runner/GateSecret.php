<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * The environment variables that hold a secret the gate itself reads, by
 * default: the OTLP collector's headers and the alert channels' URLs and
 * webhook secret (ADR-0016). A runner never hands one to the project's tests.
 */
enum GateSecret: string
{
    case OtlpHeaders = 'OTEL_EXPORTER_OTLP_HEADERS';
    case SlackUrl = 'MUTATION_GATE_SLACK_URL';
    case DiscordUrl = 'MUTATION_GATE_DISCORD_URL';
    case WebhookUrl = 'MUTATION_GATE_WEBHOOK_URL';
    case WebhookSecret = 'MUTATION_GATE_WEBHOOK_SECRET';
}
