<?php

declare(strict_types=1);

namespace Tests\Reach;

use PHPUnit\Framework\TestCase;

abstract class ReachBaseSpec extends TestCase
{
    protected function amount(): int
    {
        return 5;
    }
}
