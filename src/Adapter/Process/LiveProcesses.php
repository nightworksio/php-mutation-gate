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
use function is_resource;
use function is_string;

use NightWorksIO\MutationGate\Core\Runner\ProcessListing;
use NightWorksIO\MutationGate\Core\Runner\RunningProcesses;
use NightWorksIO\MutationGate\Core\Runner\ShellWords;
use NightWorksIO\MutationGate\Core\Runner\Signal;

use function posix_kill;

use const POSIX_SC_CLK_TCK;
use const POSIX_SC_PAGESIZE;

use function posix_sysconf;
use function preg_match;
use function proc_close;
use function proc_open;
use function restore_error_handler;
use function scandir;
use function set_error_handler;
use function sprintf;

/**
 * Every process running now, the one place the gate lists them from
 * (ADR-0312): read straight from Linux's `/proc`, which starts no process,
 * and listed by `ps` only on a system without it, such as macOS. The one
 * place too that sends another process a signal, by `posix_kill`, and by the
 * `kill` program only where PHP has no `posix` and `pcntl`.
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

    /** The program that signals processes where PHP cannot. */
    private const string SIGNALS = 'kill';

    /**
     * @param string $root       where the system says each process's state, where it has a `/proc`
     * @param bool   $programmed whether it signals by the `kill` program even where PHP could itself
     */
    public function __construct(private string $root = self::PROC, private bool $programmed = false)
    {
    }

    /** The processes running now, signalled by the `kill` program alone, as where PHP has no `posix` and `pcntl`. */
    public static function byProgram(): self
    {
        return new self(programmed: true);
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

    /**
     * Whether the process, or the group whose leader's id is negated, was sent
     * the signal: by `posix_kill` where PHP has it and the signals' numbers,
     * else by the `kill` program. A process or group that is not there is
     * sent nothing either way.
     */
    public function signalledUnspared(int $target, Signal $signal): bool
    {
        return ! $this->programmed && function_exists('posix_kill') && function_exists('pcntl_signal')
            ? posix_kill($target, $this->numbered($signal))
            : $this->programmed($target, $signal);
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

    /** The signal's number, where PHP has pcntl to name it. */
    private function numbered(Signal $signal): int
    {
        return match ($signal) {
            Signal::Freeze => SIGSTOP,
            Signal::Kill => SIGKILL,
            Signal::Terminate => SIGTERM,
        };
    }

    /** Whether the `kill` program sent the signal to the process, or to the group whose leader's id is negated. */
    private function programmed(int $target, Signal $signal): bool
    {
        $command = [self::SIGNALS, sprintf('-%s', $signal->value), '--', sprintf('%d', $target)];
        $pipes = [];
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        } finally {
            restore_error_handler();
        }

        return is_resource($process) && proc_close($process) === 0;
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
