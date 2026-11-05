<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Serve;

use function hrtime;
use function is_resource;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verb;

use const PHP_BINARY;

use function proc_open;
use function sprintf;
use function usleep;

/**
 * A daemon of `mutation-gate serve` started for the project in a process of
 * its own (ADR-0078), its streams on the null device, so it outlives the
 * command that started it; and the wait until it listens.
 *
 * @internal
 */
final readonly class DaemonStart
{
    /** The option that tells `serve` it was started this way, so it leaves the starter's session. */
    public const string DETACHED = 'detached';
    private const string NULL_DEVICE = '/dev/null';

    /** How long the start waits for the daemon to listen, in seconds, by default. */
    private const float STARTING = 10.0;

    private const string OPTION = '--%s';

    public function __construct(private ServeSocket $socket, private Seconds|NotGiven $within = new NotGiven())
    {
    }

    /**
     * Whether a daemon listens: one that already does, or one started by the
     * gate's script in this directory within ten seconds, or as long as it is
     * told. None starts where
     * the socket or its directory is not the user's own; a socket file no
     * daemon answers on is removed before it starts.
     */
    public function started(string $script, string $directory): bool
    {
        $running = $this->socket->connected();

        if ($running instanceof Connection) {
            $running->closed();
        }

        if (! $running instanceof NotGiven) {
            return $running instanceof Connection;
        }

        $this->socket->removed();
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $script, Verb::Serve->value, sprintf(self::OPTION, self::DETACHED)],
            [['file', self::NULL_DEVICE, 'r'], ['file', self::NULL_DEVICE, 'w'], ['file', self::NULL_DEVICE, 'w']],
            $pipes,
            $directory,
        );

        $within = $this->within instanceof Seconds ? $this->within : Seconds::of(self::STARTING);
        $end = hrtime(as_number: true) + $within->nanoseconds();

        return is_resource($process) && $this->listening($end);
    }

    /** Whether the daemon's socket is there before the end, on `hrtime`'s clock; a daemon makes it as it listens. */
    private function listening(int|float $end): bool
    {
        while (! $this->socket->isThere() && hrtime(as_number: true) < $end) {
            usleep(Polling::interval()->microseconds());
        }

        return $this->socket->isThere();
    }
}
