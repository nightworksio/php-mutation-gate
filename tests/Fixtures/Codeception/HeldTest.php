<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fixtures\Codeception;

use Codeception\Attribute\Group;
use Codeception\Test\Unit;
use NightWorksIO\MutationGate\Attribute\Holds;

/** A unit test whose tests the Codeception runner's own tests read: one held by its class alone, one by its own path too. */
#[Holds('src/Money.php')]
#[Group('fast')]
final class HeldTest extends Unit
{
    public function testIsHeldByItsCase(): void
    {
        $this->expectNotToPerformAssertions();
    }

    #[Holds('src/Held.php')]
    public function testIsHeldByItsOwnPathToo(): void
    {
        $this->expectNotToPerformAssertions();
    }
}
