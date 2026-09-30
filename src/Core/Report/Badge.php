<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;

/** `badge.json`, for shields.io's endpoint badge: the whole project's score in its colour. */
final readonly class Badge
{
    public static function json(Score|NothingToMutate $score, BadgeColors $colors): string
    {
        return Json::encode([
            'schemaVersion' => 1,
            'label' => 'mutation score',
            'message' => Percent::of($score),
            'color' => $colors->colorOf($score),
        ]);
    }
}
