<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Alert;

use NightWorksIO\MutationGate\Core\Alert\Alert;
use NightWorksIO\MutationGate\Core\Alert\DiscordMessage;
use NightWorksIO\MutationGate\Core\Alert\SlackMessage;
use NightWorksIO\MutationGate\Core\Alert\WebhookPayload;
use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Config\BuiltinReporter;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\GateSecret;

/** Where an alert goes, the variable its URL is read from by default, and how it is written there (ADR-0016). */
enum Channel
{
    case Slack;
    case Discord;
    case Webhook;

    /** The channel of a built-in reporter that sends alerts; none for another reporter. */
    public static function of(BuiltinReporter $reporter): self|NotGiven
    {
        return match ($reporter) {
            BuiltinReporter::Slack => self::Slack,
            BuiltinReporter::Discord => self::Discord,
            BuiltinReporter::Webhook => self::Webhook,
            BuiltinReporter::Json, BuiltinReporter::JUnit, BuiltinReporter::Sarif, BuiltinReporter::Html,
            BuiltinReporter::Tests, BuiltinReporter::KillMatrix, BuiltinReporter::GitLab, BuiltinReporter::Sonar,
            BuiltinReporter::Badge, BuiltinReporter::Console, BuiltinReporter::Problems, BuiltinReporter::Otlp,
            BuiltinReporter::GitHubAnnotations, BuiltinReporter::GitHubSummary,
            BuiltinReporter::GitHubComment => NotGiven::value(),
        };
    }

    /** The environment variable that holds the URL unless `with.urlEnv` names another. */
    public function urlEnv(): string
    {
        return match ($this) {
            self::Slack => GateSecret::SlackUrl->value,
            self::Discord => GateSecret::DiscordUrl->value,
            self::Webhook => GateSecret::WebhookUrl->value,
        };
    }

    /** How the reader knows it: *Slack*, *Discord* or *the webhook*. */
    public function said(): string
    {
        return match ($this) {
            self::Slack => 'Slack',
            self::Discord => 'Discord',
            self::Webhook => 'the webhook',
        };
    }

    public function message(Alert $alert, CiRun $run): string
    {
        return match ($this) {
            self::Slack => SlackMessage::json($alert, $run),
            self::Discord => DiscordMessage::json($alert, $run),
            self::Webhook => WebhookPayload::json($alert, $run),
        };
    }
}
