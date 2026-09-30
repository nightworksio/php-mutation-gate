<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support\Naming;

use PHPUnit\Framework\TestCase;

/** A test class PHPUnit never runs, being abstract, which the plugin names nothing of. */
abstract class AbstractNamedTest extends TestCase
{
    final public function testShared(): void
    {
        $this->addToAssertionCount(1);
    }
}
