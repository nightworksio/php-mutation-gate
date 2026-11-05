<?php

declare(strict_types=1);

namespace Library;

use function hash_equals;

/** Whether a token a user gives is the one known, compared in constant time. */
final readonly class Compared
{
    public function same(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }
}
