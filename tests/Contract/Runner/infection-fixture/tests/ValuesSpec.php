<?php

declare(strict_types=1);

namespace Tests;

use Library\Unexecutable\Tier;
use Library\Unexecutable\Values;
use Library\Unexecutable\Weight;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

// Reads every value src/Unexecutable declares, so a mutant of any of them
// would be killed if Infection made one.
final class ValuesSpec extends TestCase
{
    #[Test]
    public function readsEveryValue(): void
    {
        self::assertSame(7, Values::RATE);
        self::assertSame(13, Values::$count);
        self::assertSame(17, new Values()->cents);
        self::assertSame(1, Tier::First->value);
        self::assertSame(5, new ReflectionClass(Values::class)->getAttributes(Weight::class)[0]->newInstance()->kilos);
    }

    #[Test]
    public function discountsByTheDefault(): void
    {
        self::assertSame(10, new Values()->discount());
    }
}
