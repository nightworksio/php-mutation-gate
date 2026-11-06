<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Cost\NoHistory;
use NightWorksIO\MutationGate\Core\Cost\SavedLately;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;

use function sprintf;

/**
 * Badges for shields.io's endpoint: `badge.json`, the whole project's score
 * in its colour, and `savings.json`, the mutation time the gate saved over
 * the savings window, 30 days unless `costs.savingsWindow` says otherwise
 * (ADR-0009, and ADR-0017).
 *
 * @internal
 */
final readonly class Badge
{
    private const string SAVED = 'blue';

    private const string SAVED_IN = '%s / %s';

    private const string NO_HISTORY = 'no history yet';

    public static function json(Score|NothingToMutate $score, BadgeColors $colors): string
    {
        return JsonText::encode([
            'schemaVersion' => 1,
            'label' => 'mutation score',
            'message' => Percent::of($score),
            'color' => $colors->colorOf($score),
        ]);
    }

    /** The mutation time saved in the savings window, or that there is no history yet. */
    public static function savings(SavedLately|NoHistory $saved): string
    {
        $message = $saved instanceof SavedLately
            ? sprintf(self::SAVED_IN, $saved->saved()->text(), $saved->window()->said())
            : self::NO_HISTORY;

        return JsonText::encode([
            'schemaVersion' => 1,
            'label' => 'mutation time saved',
            'message' => $message,
            'color' => $saved instanceof SavedLately ? self::SAVED : BadgeColors::NONE,
        ]);
    }
}
