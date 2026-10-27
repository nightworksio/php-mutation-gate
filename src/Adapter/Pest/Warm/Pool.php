<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function array_key_exists;
use function getenv;
use function getmypid;
use function is_resource;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Core\OwnScript;

use const PHP_BINARY;

use function proc_close;
use function proc_open;
use function strval;

/**
 * A Pest run's warm workers, kept in Pest's parent process (ADR-0023): where
 * the gate names a directory for them, the first mutant's
 * run of each place starts that place's worker, and every mutant's run of
 * the place is asked of it through a client, which stands for the run as
 * Pest's process and prints what the run prints. A place whose worker could
 * not start runs its mutants as Pest ships them.
 */
final class Pool
{
    private const string SHELL = '/bin/sh';

    /**
     * Starts the worker reading nothing and appending to its place's output
     * files, which the shell opens, as a stream wrapper a test or a mutant's
     * run puts over files hands PHP no descriptor to give a process.
     */
    private const string REDIRECTED = 'out=$1; err=$2; shift 2; exec "$@" < /dev/null >> "$out" 2>> "$err"';

    /** @var array<string, bool> whether each place's worker started, by the file of its process id */
    private static array $started = [];

    /**
     * What goes before the command line of a mutant's run in this place: the
     * client that asks the place's worker for it; nothing where the run has
     * no warm workers, or the place's could not start.
     *
     * @return list<string>
     */
    public static function prefix(int $place): array
    {
        $directory = getenv(GateVariable::Warm->value);

        if (! is_string($directory)) {
            return [];
        }

        $seat = Seat::at($directory, $place);

        if (! array_key_exists($seat->pid(), self::$started)) {
            self::$started[$seat->pid()] = self::started($seat);
        }

        return self::$started[$seat->pid()]
            ? [PHP_BINARY, ...OwnScript::PestClient->started(), $directory, strval($place)]
            : [];
    }

    /**
     * Whether the place's worker started: its script leaves the process it
     * was started as for one of its own that the system takes on, once that
     * one has written its process id where its clients read it, so no worker
     * that ends is left for Pest's process to reap.
     */
    private static function started(Seat $seat): bool
    {
        $command = [
            self::SHELL,
            '-c',
            self::REDIRECTED,
            'sh',
            $seat->out(),
            $seat->err(),
            PHP_BINARY,
            ...OwnScript::PestWorker->started(),
            $seat->directory(),
            strval($seat->place()),
            strval(getmypid()),
        ];
        $worker = proc_open($command, [], $pipes);

        return is_resource($worker) && proc_close($worker) === 0;
    }
}
