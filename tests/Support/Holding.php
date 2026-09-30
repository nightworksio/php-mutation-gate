<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;
use NightWorksIO\MutationGate\Attribute\Holds;

/**
 * What carries a `#[Holds]` in the tests of the attribute and of Pest's
 * filter. The gate reads every `#[Holds]` in the suite's files as a holding
 * Pest must list a group for, and none of these is a test, so they live here,
 * outside the suite's directories.
 */
final readonly class Holding
{
    /** A test's closure holding src/Money.php and src/Held.php. */
    public static function moneyAndHeld(): Closure
    {
        return #[Holds('src/Money.php')] #[Holds('src/Held.php')] static fn(): bool => true;
    }

    /** A test's closure holding src/Money.php twice. */
    public static function moneyTwice(): Closure
    {
        return #[Holds('src/Money.php')] #[Holds('src/Money.php')] static function (): void {
        };
    }

    /** A test's closure holding src/Money.php. */
    public static function money(): Closure
    {
        return #[Holds('src/Money.php')] static fn(): bool => true;
    }

    /** A test's closure holding src/Money.php that is not static, as Pest requires of a test it builds. */
    public static function moneyBound(): Closure
    {
        return #[Holds('src/Money.php')] fn(): bool => true;
    }

    /** A describe's closure holding src/Held.php, which runs its body. */
    public static function held(Closure $body): Closure
    {
        return #[Holds('src/Held.php')] static function () use ($body): void {
            $body();
        };
    }

    /** A describe's closure holding src/Kernel.php, which runs its body. */
    public static function kernel(Closure $body): Closure
    {
        return #[Holds('src/Kernel.php')] static function () use ($body): void {
            $body();
        };
    }

    /** An object of a class holding src/Kernel.php and src/Http. */
    public static function kernelAndHttp(): object
    {
        return new #[Holds('src/Kernel.php'), Holds('src/Http')] class {};
    }
}
