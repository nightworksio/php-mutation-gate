<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function array_key_exists;
use function function_exists;
use function getenv;
use function getmypid;
use function is_dir;
use function is_file;
use function is_resource;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use const PHP_BINARY;

use function proc_close;
use function proc_open;
use function strval;
use function usleep;

/**
 * A Pest run's warm workers, kept in Pest's parent process (ADR-0023): where
 * the gate names a directory for them and PHP can fork, the first mutant's
 * run of each place starts that place's worker, and every mutant's run of
 * the place is asked of it through a client, which stands for the run as
 * Pest's process and prints what the run prints. A place whose worker could
 * not start runs its mutants as Pest ships them.
 */
final class Pool
{
    private const string WORKER = 'mutation-gate-pest-worker';

    private const string CLIENT = 'mutation-gate-pest-client';

    /** The mode of the workers' directory: its owner's alone, as each request holds its run's environment. */
    private const int OWNER_ONLY = 0o700;

    /** @var array<string, bool> whether each place's worker started, by the file of its process id */
    private static array $started = [];

    /**
     * What goes before the command line of a mutant's run in this place: the
     * client that asks the place's worker for it; nothing where the run has
     * no warm workers.
     *
     * @return list<string>
     */
    public static function prefix(int $place): array
    {
        $directory = (string) getenv(GateVariable::Warm->value);
        $seat = Seat::at($directory, $place);
        $warm = $directory !== '' && self::canFork();

        if ($warm && ! array_key_exists($seat->pid(), self::$started)) {
            self::$started[$seat->pid()] = self::started($seat);
        }

        return $warm && self::$started[$seat->pid()]
            ? [PHP_BINARY, ThisPackage::script(self::CLIENT), $directory, strval($place)]
            : [];
    }

    /**
     * Whether the place's worker started: its script leaves the process it
     * was started as at once, for one of its own that the system takes on,
     * so no worker that ends is left for Pest's process to reap, and writes
     * that process's id where its clients read it.
     */
    private static function started(Seat $seat): bool
    {
        $made = is_dir($seat->directory()) || mkdir($seat->directory(), self::OWNER_ONLY, recursive: true);
        $command = [
            PHP_BINARY,
            ThisPackage::script(self::WORKER),
            $seat->directory(),
            strval($seat->place()),
            strval(getmypid()),
        ];
        $streams = [['file', '/dev/null', 'r'], ['file', $seat->out(), 'a'], ['file', $seat->err(), 'a']];
        $worker = $made ? proc_open($command, $streams, $pipes) : false;
        $left = is_resource($worker) && proc_close($worker) === 0;

        if ($left) {
            Polling::wait(
                Seconds::of(Seat::WAIT),
                static fn(): bool => is_file($seat->pid()),
                usleep(...),
            );
        }

        return $left && is_file($seat->pid());
    }

    /** Whether PHP can fork a worker's children, and tell whether a process is still there. */
    private static function canFork(): bool
    {
        return function_exists('pcntl_fork') && function_exists('posix_kill');
    }
}
