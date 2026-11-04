<?php

declare(strict_types=1);

namespace StaticCheckFixture\Composition;

use function getenv;
use function is_string;

final class Wiring
{
    public function cents(): int
    {
        $told = getenv('CENTS');

        return is_string($told) ? (int) $told : 100;
    }

    public function rate(): int
    {
        return 2;
    }
}
