<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

/**
 * The environment variables that hold a secret the gate itself reads, by
 * default: the OTLP collector's headers, the alert channels' URLs and
 * webhook secret (ADR-0016), and the tokens the GitLab and Bitbucket comments
 * are written with (ADR-0024). A runner never hands one to the project's tests.
 */
enum GateSecret: string
{
    case OtlpHeaders = 'OTEL_EXPORTER_OTLP_HEADERS';
    case SlackUrl = 'MUTATION_GATE_SLACK_URL';
    case DiscordUrl = 'MUTATION_GATE_DISCORD_URL';
    case WebhookUrl = 'MUTATION_GATE_WEBHOOK_URL';
    case WebhookSecret = 'MUTATION_GATE_WEBHOOK_SECRET';
    case GitLabComment = 'MUTATION_GATE_GITLAB_TOKEN';
    case BitbucketComment = 'MUTATION_GATE_BITBUCKET_TOKEN';

    /** GitHub's CLI token, which `doctor --online` asks GitHub with where `GITHUB_TOKEN` is not set. */
    case GitHubCliToken = 'GH_TOKEN';
}
