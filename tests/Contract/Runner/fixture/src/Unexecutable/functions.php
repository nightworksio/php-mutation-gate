<?php

declare(strict_types=1);

namespace Library\Unexecutable;

// Composer's files autoload loads this before any test and before any plugin,
// so its constant runs where no test's coverage sees it, and Pest's override
// cannot replace the file.
const STARTING = 3;

/** A plain function whose parameter's default only a call that leaves it out reads. */
function tax(int $rate = 21): int
{
    return $rate;
}
