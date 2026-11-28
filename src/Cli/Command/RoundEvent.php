<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Format\JsonText;

/**
 * What a round of `watch` says as it ends with `--output=events`, as one
 * line of JSON after its mutants' (ADR-0146): how it ended, how many mutants
 * it said, and why it judged none where it could not. A round that reached
 * nothing says so, where it would say nothing.
 *
 * @internal
 */
final readonly class RoundEvent
{
    /** The event of a round a change stopped, or of one that ended as this, having said this many mutants. */
    public static function line(bool $stopped, Judged|Invalid|CannotJudge $ended, int $mutants): string
    {
        return JsonText::compact([
            'round' => RoundEnded::of($stopped, $ended)->value,
            'mutants' => $mutants,
            'why' => ! $stopped && $ended instanceof CannotJudge ? $ended->why() : null,
        ]);
    }
}
