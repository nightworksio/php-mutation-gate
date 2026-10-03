<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quirks\Tally;

/** A test PHPUnit calls risky, which runs before every other, as its name sorts first. */
final class AaRiskySpec extends TestCase
{
    #[Test]
    public function addsAndChecksNothing(): void
    {
        new Tally()->add(2, 3);
    }
}
