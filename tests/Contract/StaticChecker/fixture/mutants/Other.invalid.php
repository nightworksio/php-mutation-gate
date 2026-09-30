<?php

declare(strict_types=1);

namespace StaticCheckFixture;

final class Other
{
    public function twice(int $cents): int
    {
        return $cents . 2;
    }
}
