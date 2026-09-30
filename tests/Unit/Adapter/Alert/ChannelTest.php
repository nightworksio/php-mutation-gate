<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Alert\Channel;
use NightWorksIO\MutationGate\Core\Alert\Alert;
use NightWorksIO\MutationGate\Core\Alert\AlertEvent;
use NightWorksIO\MutationGate\Core\Alert\DiscordMessage;
use NightWorksIO\MutationGate\Core\Alert\SlackMessage;
use NightWorksIO\MutationGate\Core\Alert\WebhookPayload;
use NightWorksIO\MutationGate\Core\Report\TrendEntry;
use NightWorksIO\MutationGate\Tests\Support\Previous;
use NightWorksIO\MutationGate\Tests\Support\Verdicts;

it('reads each URL from its own variable, and writes each message its own way', function (): void {
    $alert = Alert::of(AlertEvent::Failed, Verdicts::failing(), TrendEntry::none());

    expect(array_map(static fn(Channel $channel): string => $channel->urlEnv(), Channel::cases()))
        ->toBe(['MUTATION_GATE_SLACK_URL', 'MUTATION_GATE_DISCORD_URL', 'MUTATION_GATE_WEBHOOK_URL'])
        ->and(array_map(static fn(Channel $channel): string => $channel->said(), Channel::cases()))
        ->toBe(['Slack', 'Discord', 'the webhook'])
        ->and(Channel::Slack->message($alert, Previous::ci()))->toBe(SlackMessage::json($alert, Previous::ci()))
        ->and(Channel::Discord->message($alert, Previous::ci()))->toBe(DiscordMessage::json($alert, Previous::ci()))
        ->and(Channel::Webhook->message($alert, Previous::ci()))->toBe(WebhookPayload::json($alert, Previous::ci()));
});
