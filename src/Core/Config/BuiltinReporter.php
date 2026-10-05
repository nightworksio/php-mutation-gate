<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

/** The reporters this package builds in, by the name a config or the gate chooses each by. */
enum BuiltinReporter: string
{
    case Console = 'console';

    case Problems = 'problems';

    case Json = 'json';

    case JUnit = 'junit';

    case Sarif = 'sarif';

    case Html = 'html';

    case Tests = 'tests';

    case KillMatrix = 'kill-matrix';

    case GitLab = 'gitlab';

    case Sonar = 'sonar';

    case Slack = 'slack';

    case Discord = 'discord';

    case Webhook = 'webhook';

    case Otlp = 'otlp';

    case GitHubAnnotations = 'github-annotations';

    case GitHubSummary = 'github-summary';

    case GitHubComment = 'github-comment';

    case Badge = 'badge';

    public function named(): Name
    {
        return Name::of($this->value);
    }

    /** Whether a `reports` entry of this reporter names the path it writes to. */
    public function entryPath(): EntryPath
    {
        return match ($this) {
            self::Json, self::JUnit, self::Sarif, self::Html, self::Tests, self::KillMatrix, self::GitLab,
            self::Sonar => EntryPath::Required,
            self::Badge => EntryPath::Optional,
            self::Console, self::Problems, self::Slack, self::Discord, self::Webhook, self::Otlp,
            self::GitHubAnnotations, self::GitHubSummary, self::GitHubComment => EntryPath::Refused,
        };
    }

    /** Whether this reporter sends an alert, reading its URL and secret from the variables `AlertOption` names. */
    public function alerts(): bool
    {
        return match ($this) {
            self::Slack, self::Discord, self::Webhook => true,
            self::Json, self::JUnit, self::Sarif, self::Html, self::Tests, self::KillMatrix, self::GitLab,
            self::Sonar, self::Badge, self::Console, self::Problems, self::Otlp, self::GitHubAnnotations,
            self::GitHubSummary, self::GitHubComment => false,
        };
    }
}
