<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function random_bytes;
use function sodium_bin2hex;

/**
 * Text drawn at random, which no other local user can guess and no other run
 * draws alike: the name of a split stream's directory and of a database
 * copied for a child. It reaches nothing a plan is cut from (ADR-0117).
 *
 * @internal
 */
final readonly class Unguessable
{
    /**
     * Text of this many random bytes, as hexadecimal digits.
     *
     * @param positive-int $bytes
     */
    public static function text(int $bytes): string
    {
        return sodium_bin2hex(random_bytes($bytes));
    }
}
