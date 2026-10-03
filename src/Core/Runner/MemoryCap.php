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

    /** Where the ini file is written before it is moved into place, whole, beside it. */
    public const string STAGED = 'memory-cap.ini.staged';

    /**
     * The cap's directory among a runner's own files, by the id of the gate's
     * process, so two runs in one checkout never share it.
     */
    public const string DIRECTORY = 'php/%d';

    /** PHP's shorthand for an amount of memory, its unit in either case. */
    private const string SHORTHAND = '/\A(?<number>[1-9]\d*)(?<unit>[KMG]?)\z/i';


    private const string UNREADABLE
        = '"%s" is not an amount of memory. Write it as PHP\'s memory_limit does, such as 512M or 1G, or -1 for none.';

    private const string TOO_LARGE = '"%s" is more memory than PHP can count. Write -1 for no cap.';

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

    /**
     * A cap of a whole number of units, such as `MemoryCap::of(512,
     * MemoryUnit::Megabytes)`, kept in the largest unit it is a whole number
     * of: `1024M` is `1G`, so one cap is written, and keyed, one way.
     */
    public static function of(int $number, MemoryUnit $unit): self
    {
        $bytes = $number * $unit->bytes();
        $largest = $unit;

        foreach (MemoryUnit::cases() as $larger) {
            $largest = $larger->bytes() > $largest->bytes() && $bytes % $larger->bytes() === 0 ? $larger : $largest;
        }

        return new self(intdiv($bytes, $largest->bytes()), $largest);
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

        if (preg_match(self::SHORTHAND, $written, $parts) !== 1) {
            return CannotJudge::because(sprintf(self::UNREADABLE, $written));
        }

        $number = (int) $parts['number'];
        $unit = MemoryUnit::from(mb_strtoupper($parts['unit']));

        return (string) $number === $parts['number'] && $number <= intdiv(PHP_INT_MAX, $unit->bytes())
            ? self::of($number, $unit)
            : CannotJudge::because(sprintf(self::TOO_LARGE, $written));
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



    /** The ini file that sets this cap, where it caps anything. */
    public function ini(): string
    {
        return sprintf("memory_limit=%s\n", $this->written());
    }

    /**
     * `PHP_INI_SCAN_DIR` for a PHP process that also reads the ini files in
     * this directory, after those it would read already: the value the gate
     * inherited, or PHP's own directories, which a value that starts with
     * the separator stands for. An inherited empty value scans none of PHP's
     * directories, so it scans this one alone. A runner starts each mutant as
     * a PHP process of its own, which takes no option of the command that
     * started the runner but inherits its environment. A project that sets
     * `memory_limit` itself, such as with `<ini name="memory_limit">` in
     * `phpunit.xml`, sets it later, and so wins.
     */
    public static function scanning(string|false $inherited, string $directory): string
    {
        return match ($inherited) {
            false => sprintf('%s%s', PATH_SEPARATOR, $directory),
            '' => $directory,
            default => sprintf('%s%s%s', $inherited, PATH_SEPARATOR, $directory),
        };
    }

    /** How many bytes it holds: none where it caps nothing. */
    public function bytes(): int
    {
        return $this->unit instanceof MemoryUnit ? $this->number * $this->unit->bytes() : 0;
    }
}
