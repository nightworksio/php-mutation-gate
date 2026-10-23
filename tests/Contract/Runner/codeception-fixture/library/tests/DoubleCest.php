<?php

declare(strict_types=1);

namespace Tests;

use Codeception\Attribute\Examples;
use Codeception\Example;
use Library\Held;
use PHPUnit\Framework\Assert;

/** Doubles each example's amount, as a Cest names each example by its index. */
final class DoubleCest
{
    #[Examples(4, 8)]
    #[Examples(5, 10)]
    public function doublesAnAmount(Tester $I, Example $example): void
    {
        Assert::assertSame($example[1], new Held()->double($example[0]));
    }
}
