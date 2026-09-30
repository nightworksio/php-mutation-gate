<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function sprintf;

/**
 * A file an earlier run left where a run writes its own, which the gate
 * could not remove, so the run could not be told from the earlier one.
 */
final readonly class Leftover
{
    private const string LEFT = 'An earlier run left %s, and the gate cannot remove it.';

    public static function at(string $file): CannotJudge
    {
        return CannotJudge::because(sprintf(self::LEFT, $file));
    }
}
