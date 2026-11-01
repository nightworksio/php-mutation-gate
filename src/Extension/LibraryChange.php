<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;

use function sprintf;

/**
 * Each change the runner contract's library allows (`resources/contract/runner/src`), by a name no runner uses,
 * with the file, line and status every runner reports for it, judged by the tests `LibraryTest` lists.
 */
enum LibraryChange: string
{
    /** `Money::add()` subtracts: its tests kill it. */
    case Adds = 'adds';

    /** `Money::isLarge()` takes 100 as large: no test tells, so it survives. */
    case Large = 'large';

    /** `Money::unused()` adds: no test runs it. */
    case Unused = 'unused';

    /** `Money::drain()` counts up: its loop never ends, so it times out. */
    case Drains = 'drains';

    /** `Held::double()` subtracts: its group's test cannot tell, though a test outside the group would. */
    case Held = 'held';

    private const string MONEY = 'src/Money.php';

    private const string HELD = 'src/Held.php';

    private const int FIRST_METHOD_LINE = 11;

    private const int LARGE_LINE = 16;

    private const int UNUSED_LINE = 21;

    private const int DRAINS_LINE = 27;

    /** A diff of one line, as the gate spells a mutant's. */
    private const string DIFF = "@@ @@\n-%s\n+%s";

    public function file(): Path
    {
        return Path::of($this === self::Held ? self::HELD : self::MONEY);
    }

    public function line(): int
    {
        return match ($this) {
            self::Adds, self::Held => self::FIRST_METHOD_LINE,
            self::Large => self::LARGE_LINE,
            self::Unused => self::UNUSED_LINE,
            self::Drains => self::DRAINS_LINE,
        };
    }

    /** The line as the library writes it. */
    public function removed(): string
    {
        return match ($this) {
            self::Adds => 'return $a + $b;',
            self::Large => 'return $amount > 100;',
            self::Unused => 'return $amount - 1;',
            self::Drains => '$amount--;',
            self::Held => 'return $amount + $amount;',
        };
    }

    /** The line as the change writes it. */
    public function added(): string
    {
        return match ($this) {
            self::Adds => 'return $a - $b;',
            self::Large => 'return $amount >= 100;',
            self::Unused => 'return $amount + 1;',
            self::Drains => '$amount++;',
            self::Held => 'return $amount - $amount;',
        };
    }

    /** The change as a diff, the way the gate hashes a mutant's id from it. */
    public function diff(): string
    {
        return sprintf(self::DIFF, $this->removed(), $this->added());
    }

    /** What every runner reports the change's mutant as, judged by the library's tests. */
    public function status(): MutantStatus
    {
        return match ($this) {
            self::Adds => MutantStatus::Killed,
            self::Large, self::Held => MutantStatus::Survived,
            self::Unused => MutantStatus::Uncovered,
            self::Drains => MutantStatus::TimedOut,
        };
    }

    public function family(): MutatorFamily
    {
        return $this === self::Large ? MutatorFamily::Boundary : MutatorFamily::Arithmetic;
    }
}
