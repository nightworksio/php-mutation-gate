<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function intval;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * Seconds a layer sets, as a config writes them: a whole count; nothing where the layer sets none.
 *
 * @internal
 */
final readonly class WholeSeconds
{
    public static function written(Seconds|Absent $seconds): int|Absent
    {
        return $seconds instanceof Seconds ? intval($seconds->seconds()) : $seconds;
    }
}
