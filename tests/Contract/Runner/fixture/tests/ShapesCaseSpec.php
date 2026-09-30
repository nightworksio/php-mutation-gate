<?php

declare(strict_types=1);

namespace Tests;

use NightWorksIO\MutationGate\Attribute\Holds;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function seen;

// A PHPUnit class run by Pest holds its path by PHPUnit's own #[Group], which
// its #[Holds] names exactly (ADR-0005). It sits in a namespace because Pest
// selects a mutant's covering tests by a filter that expects one.
#[Holds('src/Shapes.php')]
#[Group('holds:src/Shapes.php')]
final class ShapesCaseSpec extends TestCase
{
    public function testItIsHeldAsAClass(): void
    {
        self::assertIsBool(seen('phpunit class'));
    }
}
