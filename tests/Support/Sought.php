<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;

/** A mutant id or prefix as a test writes it. */
final class Sought
{
    /** The prefix written this way; a test that writes one wrongly seeks `000000`, and its expectation fails. */
    public static function of(string $prefix): IdPrefix
    {
        $parsed = IdPrefix::parse($prefix);

        return $parsed instanceof IdPrefix ? $parsed : self::of('000000');
    }
}
