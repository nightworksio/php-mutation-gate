<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\Flow\Judged;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;

/**
 * How a round of `watch` ended, as its event names it (see RoundEvent).
 *
 * @internal
 */
enum RoundEnded: string
{
    /** A change stopped it. */
    case Changed = 'changed';

    /** It judged what it reached. */
    case Judged = 'judged';

    /** The configuration is not valid. */
    case Invalid = 'invalid';

    /** It could not judge. */
    case Unjudged = 'unjudged';

    /** How a round a change stopped, or one that ended as this, ended. */
    public static function of(bool $stopped, Judged|Invalid|CannotJudge $ended): self
    {
        return $stopped ? self::Changed : match (true) {
            $ended instanceof Judged => self::Judged,
            $ended instanceof Invalid => self::Invalid,
            default => self::Unjudged,
        };
    }
}
