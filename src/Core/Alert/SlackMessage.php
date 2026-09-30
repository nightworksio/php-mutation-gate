<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\JsonText;

use function sprintf;

/**
 * An alert as a Slack message: the title as the one-line `text` fallback,
 * then Block Kit, a header, a section per group and a link to the run. Each
 * block is cut to Slack's limit (ADR-0016, decision 12).
 */
final readonly class SlackMessage
{
    /** The most characters a header block holds. */
    private const int HEADER = 150;

    /** The most characters a section's text holds. */
    private const int SECTION = 3_000;

    public static function json(Alert $alert, CiRun $run): string
    {
        $title = AlertTitle::of($alert, $run);
        $blocks = [['type' => 'header', 'text' => self::text('plain_text', Fit::line($title, self::HEADER))]];

        foreach (AlertLines::of($alert, Chat::Slack) as [$heading, $lines]) {
            $blocks[] = self::section(Fit::lines([Chat::Slack->heading($heading), ...$lines], self::SECTION));
        }

        $blocks[] = self::section(sprintf('<%s|The run>', Chat::Slack->text($run->url())));

        return JsonText::encode(['text' => Chat::Slack->text($title), 'blocks' => $blocks]);
    }

    /** @return array{type: string, text: array{type: string, text: string}} */
    private static function section(string $text): array
    {
        return ['type' => 'section', 'text' => self::text('mrkdwn', $text)];
    }

    /** @return array{type: string, text: string} */
    private static function text(string $type, string $text): array
    {
        return ['type' => $type, 'text' => $text];
    }
}
