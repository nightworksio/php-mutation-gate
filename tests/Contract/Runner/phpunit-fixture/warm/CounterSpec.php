<?php

declare(strict_types=1);

namespace Warm\Tests;

use function class_exists;
use function getenv;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function putenv;

use Warm\Counter;

final class CounterSpec extends TestCase
{
    public static int $runs = 0;

    #[Test]
    public function adds(): void
    {
        self::assertSame(0, self::$runs++, 'an earlier run left its static behind');
        self::assertFalse(getenv('WARM_LEFTOVER'), 'an earlier run left its variable behind');
        self::assertFalse(class_exists('Warm\Leftover', autoload: false), 'an earlier run left its class behind');
        putenv('WARM_LEFTOVER=1');
        eval('namespace Warm; final class Leftover {}');
        self::assertSame(3, Counter::add(1, 2));
        Counter::next(1);
    }
}
