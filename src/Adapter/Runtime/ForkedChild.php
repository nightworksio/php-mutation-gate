<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Runtime;

use function mt_srand;

use const PHP_INT_MAX;
use const PHP_INT_MIN;

use function random_int;

/**
 * What a child a warm worker forked takes on so it runs as a fresh process
 * would: a fork copies PHP's Mersenne Twister, which `mt_rand()`, `rand()`,
 * `shuffle()` and `array_rand()` draw from, so every child would replay its
 * parent's sequence where each fresh process draws one of its own. The child
 * seeds it again from the system's generator.
 *
 * @internal
 */
final readonly class ForkedChild
{
    public static function reseeded(): void
    {
        mt_srand(random_int(PHP_INT_MIN, PHP_INT_MAX));
    }
}
