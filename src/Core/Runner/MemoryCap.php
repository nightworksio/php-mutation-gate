<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function intdiv;
use function max;
use function mb_strtoupper;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_match;
use function sprintf;

/**
 * `runner.memory`: the memory each process that runs a mutant may use, as
 * PHP's `memory_limit` writes it, bytes or a whole number of K, M or G, or
 * `-1` for no cap (ADR-0004, decision 9). A mutant that runs away with
 * memory then stops alone, rather than taking the machine with it.
 */
final readonly class MemoryCap
{
    /** What PHP writes for no cap. */
    public const string NONE = '-1';

    /** The variable PHP reads the directories of its extra ini files from. */
    public const string SCAN_DIR = 'PHP_INI_SCAN_DIR';

    /** The ini file that sets the cap, in a directory of its own for `PHP_INI_SCAN_DIR` to name. */
    public const string FILE = 'memory-cap.ini';

    /** Why a run cannot start capped, by the ini file it cannot write. */
    public const string UNWRITTEN = 'The memory cap cannot be written to %s. Make the directory writable.';

    /** PHP's shorthand for an amount of memory, its unit in either case. */
    private const string SHORTHAND = '/^(?<number>[1-9]\d*)(?<unit>[KMG]?)$/i';

    /**
     * How many times what the suite needed a cap should hold: a mutant rarely
     * needs twice what its whole suite does, unless it runs away.
     */
    private const int ROOM = 2;

    private const string UNREADABLE
        = '"%s" is not an amount of memory. Write it as PHP\'s memory_limit does, such as 512M or 1G, or -1 for none.';

    private function __construct(private int $number, private MemoryUnit|Uncapped $unit)
    {
    }

    public static function standard(): self
    {
        return self::of(1, MemoryUnit::Gigabytes);
    }

    public static function none(): self
    {
        return new self(0, Uncapped::Memory);
    }

    /** A cap of a whole number of units, such as `MemoryCap::of(512, MemoryUnit::Megabytes)`. */
    public static function of(int $number, MemoryUnit $unit): self
    {
        return new self($number, $unit);
    }

    /** The smallest cap of whole megabytes that holds this many bytes. */
    public static function atLeast(int $bytes): self
    {
        $megabyte = MemoryUnit::Megabytes->bytes();

        return self::of(max(1, intdiv($bytes + $megabyte - 1, $megabyte)), MemoryUnit::Megabytes);
    }

    /** A cap as a config writes it: `512M`, `1G`, a number of bytes, or `-1` for none. */
    public static function parse(string $written): self|CannotJudge
    {
        if ($written === self::NONE) {
            return self::none();
        }

        return preg_match(self::SHORTHAND, $written, $parts) === 1
            ? self::of((int) $parts['number'], MemoryUnit::from(mb_strtoupper($parts['unit'])))
            : CannotJudge::because(sprintf(self::UNREADABLE, $written));
    }

    /** Whether it caps anything. */
    public function caps(): bool
    {
        return $this->unit instanceof MemoryUnit;
    }

    /** The cap as PHP's `memory_limit` takes it. */
    public function written(): string
    {
        return $this->unit instanceof MemoryUnit ? sprintf('%d%s', $this->number, $this->unit->value) : self::NONE;
    }

    /** How many of its unit it is, where it caps anything. */
    public function number(): int
    {
        return $this->number;
    }

    public function unit(): MemoryUnit|Uncapped
    {
        return $this->unit;
    }

    /**
     * Whether a `memory_limit` a project sets itself, such as in `phpunit.xml`,
     * lets a process use more than this cap: none, or more bytes than it.
     */
    public function isExceededBy(self $limit): bool
    {
        return $this->caps() && (! $limit->caps() || $limit->bytes() > $this->bytes());
    }

    /** Whether this cap holds what a suite needed, as `peak`, with room to spare: none always does. */
    public function leavesRoomFor(self $peak): bool
    {
        return ! $this->caps() || $this->bytes() >= $peak->withRoom()->bytes();
    }

    /** The cap that holds this much with room to spare. */
    public function withRoom(): self
    {
        return self::atLeast($this->bytes() * self::ROOM);
    }

    /** The ini file that sets this cap, where it caps anything. */
    public function ini(): string
    {
        return sprintf("memory_limit=%s\n", $this->written());
    }

    /**
     * `PHP_INI_SCAN_DIR` for a PHP process that also reads the ini files in
     * this directory, after those it would read already: the value the gate
     * inherited, or PHP's own directories, which a value that starts with
     * the separator stands for. A runner starts each mutant as a PHP process
     * of its own, which takes no option of the command that started the
     * runner but inherits its environment. A project that sets
     * `memory_limit` itself, such as with `<ini name="memory_limit">` in
     * `phpunit.xml`, sets it later, and so wins.
     */
    public static function scanning(string|false $inherited, string $directory): string
    {
        return sprintf('%s%s%s', $inherited === false ? '' : $inherited, PATH_SEPARATOR, $directory);
    }

    private function bytes(): int
    {
        return $this->unit instanceof MemoryUnit ? $this->number * $this->unit->bytes() : 0;
    }
}
