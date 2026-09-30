<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function floor;
use function max;

use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The seconds pest-plugin-mutate allows each mutant: the opening run's, and
 * the larger of five seconds and a fifth of them more, in whole seconds.
 */
final readonly class PestTimeLimit
{
    /** The least Pest adds to the opening run's seconds. */
    private const float LEAST_EXTRA = 5.0;

    /** The share of the opening run's seconds Pest adds where it is more. */
    private const float EXTRA_SHARE = 0.2;

    public static function of(Seconds $opening): Seconds
    {
        $seconds = $opening->seconds();

        return Seconds::of(floor($seconds + max(self::LEAST_EXTRA, $seconds * self::EXTRA_SHARE)));
    }
}
