<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Alert;

use NightWorksIO\MutationGate\Core\Ci\CiRun;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Format\JsonText;

/**
 * An alert as a Discord message: one embed, red for a failure, green for a
 * recovery and grey otherwise, titled and linked to the run, its groups in
 * the description. It lets nothing mention anyone, and each part is cut to
 * Discord's limit (ADR-0016, decision 12).
 */
final readonly class DiscordMessage
{
    private const int RED = 0xCB_24_31;

    private const int GREEN = 0x28_A7_45;

    private const int GREY = 0x6A_73_7D;

    /** The most characters an embed's title holds. */
    private const int TITLE = 256;

    /** The most characters of a message Discord takes, which the description stays within. */
    private const int DESCRIPTION = 2_000;

    public static function json(Alert $alert, CiRun $run): string
    {
        $lines = [];

        foreach (AlertLines::of($alert, Chat::Discord) as [$heading, $grouped]) {
            $lines = [...$lines, ...$lines === [] ? [] : [''], Chat::Discord->heading($heading), ...$grouped];
        }

        $embed = [
            'title' => Fit::line(AlertTitle::of($alert, $run), self::TITLE),
            ...$run->url() === '' ? [] : ['url' => $run->url()],
            ...$lines === [] ? [] : ['description' => Fit::lines($lines, self::DESCRIPTION)],
            'color' => match ($alert->event()) {
                AlertEvent::Failed => self::RED,
                AlertEvent::Recovered => self::GREEN,
                AlertEvent::CannotJudge, AlertEvent::FloorLowered => self::GREY,
            },
        ];

        return JsonText::encode(['allowed_mentions' => ['parse' => []], 'embeds' => [$embed]]);
    }
}
