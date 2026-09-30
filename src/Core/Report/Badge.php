<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * Badges for shields.io's endpoint: `badge.json`, the whole project's score
 * in its colour, and `savings.json`, the mutation time the gate saved over
 * the last 30 days (ADR-0009, decision 5, and ADR-0017, decision 13).
 */
final readonly class Badge
{
    private const string SAVED = 'blue';

    private const string SAVED_IN = '%s / %d days';

    private const string NO_HISTORY = 'no history yet';

    public static function json(Score|NothingToMutate $score, BadgeColors $colors): string
    {
        return Json::encode([
            'schemaVersion' => 1,
            'label' => 'mutation score',
            'message' => Percent::of($score),
            'color' => $colors->colorOf($score),
        ]);
    }

    /** The mutation time saved over the last days the savings count, or that there is no history yet. */
    public static function savings(Seconds|NoHistory $saved): string
    {
        $message = $saved instanceof Seconds
            ? sprintf(self::SAVED_IN, $saved->text(), SavingsText::DAYS)
            : self::NO_HISTORY;

        return Json::encode([
            'schemaVersion' => 1,
            'label' => 'mutation time saved',
            'message' => $message,
            'color' => $saved instanceof Seconds ? self::SAVED : BadgeColors::NONE,
        ]);
    }
}
