<?php

declare(strict_types=1);

namespace Library\Unexecutable;

/** A plain function in a file a test requires, so Pest's override serves it. */
function discount(int $percent = 10): int
{
    return $percent;
}
