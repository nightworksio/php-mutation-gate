<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Serve;

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

    private const string OPTION = '--%s';

    /** @param list<string> $launch what goes before the daemon's command line: its priority (ADR-0120) */
    public function __construct(private ServeSocket $socket, private Seconds $within, private array $launch = [])
    {
    }

    /**
     * Whether a daemon listens: one that already does, or one started by the
     * gate's script in this directory within the time it is given
     * (`local.serveStartWait`), under what it is launched with. The socket's directory is kept to the user
     * alone first, where it is the user's own; none starts where the socket
     * or its directory is not the user's own; a socket file no daemon answers
     * on is removed before it starts.
     */
    public function started(string $script, string $directory): bool
    {
        $this->socket->tightened();
        $running = $this->socket->connected();

        if (! $running instanceof NotGiven) {
            return $running instanceof Connection;
        }

        $this->socket->removed();
        $pipes = [];
        proc_open(
            [...$this->launch, PHP_BINARY, $script, Verb::Serve->value, sprintf(self::OPTION, self::DETACHED)],
            [['file', self::NULL_DEVICE, 'r'], ['file', self::NULL_DEVICE, 'w'], ['file', self::NULL_DEVICE, 'w']],
            $pipes,
            $directory,
        );
        Polling::wait($this->within, $this->socket->isThere(...), usleep(...));

        return $this->socket->isThere();
    }
}
