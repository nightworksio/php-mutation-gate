<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Runner\ProcessWatch;

/** A watch that counts its looks. */
final class CountedLooks implements ProcessWatch
{
    public private(set) int $looks = 0;

    public function look(): void
    {
        ++$this->looks;
    }
}
