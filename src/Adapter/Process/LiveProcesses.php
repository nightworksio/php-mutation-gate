<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use Closure;

use function exec;
use function explode;
use function file_get_contents;
use function function_exists;
use function implode;
use function is_array;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Runner\ProcessListing;
use NightWorksIO\MutationGate\Core\Runner\RunningProcesses;
use NightWorksIO\MutationGate\Core\Runner\ShellWords;

use const POSIX_SC_CLK_TCK;
use const POSIX_SC_PAGESIZE;

use function posix_sysconf;
use function preg_match;
use function restore_error_handler;
use function scandir;
use function set_error_handler;
use function sprintf;

/**
 * Every process running now, the one place the gate lists them from
 * (ADR-0312): read straight from Linux's `/proc`, which starts no process,
 * and listed by `ps` only on a system without it, such as macOS.
 *
 * @internal
 */
final readonly class LiveProcesses implements RunningProcesses
{
    /** Where Linux says the state of each process. */
    public const string PROC = '/proc';

    /** A process's state, under the root. */
    private const string STAT = '%s/%s/stat';

    /** The process that reads `/proc`, as `/proc` names it. */
    private const string OWN = 'self';

    /** How long the machine has been up, in seconds, first, under the root. */
    private const string UPTIME = '%s/uptime';

    /** A process's directory under the root: its id alone. */
    private const string PROCESS = '/^\d+$/';

    /** @param string $root where the system says each process's state, where it has a `/proc` */
    public function __construct(private string $root = self::PROC)
    {
    }

    /**
     * Every process running now: from `/proc` where this system has one and
     * PHP can ask how it counts, else as `ps` lists them.
     */
    public function listed(): ProcessListing
    {
        return function_exists('posix_sysconf') && is_file(sprintf(self::STAT, $this->root, self::OWN))
            ? self::read($this->root)
            : self::printed();
    }

    /** Every process `/proc` under this root says; one that ended while it was read is left out. */
    public static function read(string $root): ProcessListing
    {
        $stats = [];
        $entries = self::quietly(static fn(): array|false => scandir($root));

        foreach (is_array($entries) ? $entries : [] as $entry) {
            $stat = preg_match(self::PROCESS, $entry) === 1 ? self::content(sprintf(self::STAT, $root, $entry)) : '';
            $stats = $stat === '' ? $stats : [...$stats, $stat];
        }

        return ProcessListing::stated(
            $stats,
            (float) explode(' ', self::content(sprintf(self::UPTIME, $root)))[0],
            posix_sysconf(POSIX_SC_CLK_TCK),
            posix_sysconf(POSIX_SC_PAGESIZE),
        );
    }

    /** Every process as `ps` lists them. */
    public static function printed(): ProcessListing
    {
        $printed = [];
        exec(ShellWords::line(ProcessListing::COMMAND), $printed);

        return ProcessListing::printed(implode("\n", $printed));
    }

    /** What a file holds; nothing where it is gone, as a process's files are once it ended. */
    private static function content(string $path): string
    {
        $read = self::quietly(static fn(): string|false => file_get_contents($path));

        return is_string($read) ? $read : '';
    }

    /**
     * @param  Closure(): (list<string>|string|false) $read
     * @return list<string>|string|false
     */
    private static function quietly(Closure $read): array|string|false
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return $read();
        } finally {
            restore_error_handler();
        }
    }
}
