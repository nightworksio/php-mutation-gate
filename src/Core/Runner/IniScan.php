<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_key_exists;
use function mb_strlen;
use function mb_substr;
use function sprintf;
use function str_starts_with;

/**
 * The directories PHP scans for ini files after its own php.ini
 * (`PHP_INI_SCAN_DIR`), as a mutation run's processes add one to them
 * (ADR-0183): they keep what the variable held before, and the directory
 * they added, in variables of the gate's, so a process the gate starts from
 * one of them scans what PHP scanned before, unless it adds the directory
 * again. An empty entry in the list stands for the directory PHP was built
 * to scan; an empty variable scans none.
 *
 * @internal
 */
final readonly class IniScan
{
    /** PHP's variable of the directories it scans. */
    public const string VARIABLE = 'PHP_INI_SCAN_DIR';

    /** The gate's variable of what PHP's held before a run added a directory: `set:` and the value, or `unset`. */
    public const string KEPT = 'MUTATION_GATE_INI_SCAN_DIR';

    /** The gate's variable of the directory a run added. */
    public const string ADDED = 'MUTATION_GATE_INI_SCAN_ADDED';

    private const string UNSET = 'unset';

    private const string SET = 'set:';

    private const string BETWEEN = '%s:%s';

    /**
     * What a process started in this environment changes so it scans what
     * PHP scanned before a run added its directory; nothing where no run
     * added one. Where the variable no longer holds what the run made of it,
     * it is left as it is.
     *
     * @param  array<string, string>       $inherited
     * @return array<string, string|false>
     */
    public static function restored(array $inherited): array
    {
        if (! array_key_exists(self::KEPT, $inherited) || ! array_key_exists(self::ADDED, $inherited)) {
            return [];
        }

        $before = self::before($inherited[self::KEPT]);
        $made = self::scanned($before, $inherited[self::ADDED]);
        $current = array_key_exists(self::VARIABLE, $inherited) ? $inherited[self::VARIABLE] : false;

        return [
            ...$current === $made ? [self::VARIABLE => $before] : [],
            self::KEPT => false,
            self::ADDED => false,
        ];
    }

    /**
     * What a process started in this environment is given so PHP also scans
     * this directory, after what it scanned before any run added one.
     *
     * @param  array<string, string> $inherited
     * @return array<string, string>
     */
    public static function adding(string $directory, array $inherited): array
    {
        $restored = [...$inherited, ...self::restored($inherited)];
        $before = array_key_exists(self::VARIABLE, $restored) ? $restored[self::VARIABLE] : false;

        return [
            self::VARIABLE => self::scanned($before, $directory),
            self::KEPT => $before === false ? self::UNSET : sprintf('%s%s', self::SET, $before),
            self::ADDED => $directory,
        ];
    }

    /** What PHP's variable held, as kept: false where it was unset. */
    private static function before(string $kept): string|false
    {
        return str_starts_with($kept, self::SET) ? mb_substr($kept, mb_strlen(self::SET)) : false;
    }

    /** The variable that scans what it held and then this directory. */
    private static function scanned(string|false $before, string $directory): string
    {
        return match ($before) {
            false => sprintf(self::BETWEEN, '', $directory),
            '' => $directory,
            default => sprintf(self::BETWEEN, $before, $directory),
        };
    }
}
