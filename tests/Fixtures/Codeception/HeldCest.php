<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fixtures\Codeception;

use NightWorksIO\MutationGate\Attribute\Holds;

/** A Cest whose test the Codeception runner's own tests read, held by its own path. */
final class HeldCest
{
    #[Holds('src/Held.php')]
    public function isHeld(): string
    {
        return __FUNCTION__;
    }
}
