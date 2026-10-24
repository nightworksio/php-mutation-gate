<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function count;

use NightWorksIO\MutationGate\Core\File\Path;

/**
 * How the baseline file stands against what a run measured (ADR-0003):
 * missing where a tree or security set with a score is held to no floor at
 * all; stale where a score rose above a floor, which the baseline rises to
 * once that is committed; current otherwise.
 */
enum BaselineState: string
{
    case Current = 'current';
    case Stale = 'stale';
    case Missing = 'missing';

    public static function of(Verdict $verdict, Path $baseline): self
    {
        $trees = $verdict->trees();
        $security = $verdict->sets()->security();
        $unfloored = count(Ratchet::unfloored($trees)) + count(Ratchet::securityUnfloored($security));
        $raised = count(Ratchet::required($trees, $baseline)) + count(Ratchet::securityRequired($security, $baseline));

        return match (true) {
            $unfloored > 0 => self::Missing,
            $raised > 0 => self::Stale,
            default => self::Current,
        };
    }
}
