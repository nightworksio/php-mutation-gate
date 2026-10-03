<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Quirks\Tally;
use RuntimeException;

/** A class of tests whose `setUpBeforeClass` checks the library before any of its tests runs. */
final class AddsBeforeClassSpec extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (new Tally()->add(2, 3) !== 5) {
            throw new RuntimeException('the library cannot add');
        }
    }

    #[Test]
    public function runsOnceTheLibraryAdds(): void
    {
        self::assertTrue(true);
    }
}
