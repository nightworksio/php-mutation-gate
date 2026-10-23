<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fixtures\PhpSpec;

use NightWorksIO\MutationGate\Attribute\Holds;
use PhpSpec\ObjectBehavior;

/**
 * A spec whose examples the PhpSpec runner's own tests read: one held by its spec alone, one by its own path too.
 *
 * @extends ObjectBehavior<string, string>
 */
#[Holds('src/Money.php')]
final class HeldSpec extends ObjectBehavior
{
    public function it_is_held_by_its_spec(): void
    {
    }

    #[Holds('src/Held.php')]
    public function it_is_held_by_its_own_path_too(): void
    {
    }
}
