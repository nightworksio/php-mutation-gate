<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Serve;

use function ceil;

use Closure;

use function hrtime;
use function intdiv;
use function max;
use function min;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Core\Serve\ServeIdentity;
use NightWorksIO\MutationGate\Core\Serve\ServeRequest;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A daemon of `mutation-gate serve` (ADR-0078): it listens on the project's
 * socket and forks a child for each request, which runs the client's command
 * line and answers with its output and exit code, so a long `watch` never
 * holds up a hook. A child whose client is gone is stopped with every
 * process under it. The daemon stops once no request has come for its idle
 * time, on a request to stop, and once a client finds the gate's version,
 * composer.lock or the config changed since it started; the children
 * running then finish first.
 *
 * @internal
 */
final class Daemon
{
    private const string OTHER_ROOT = 'The daemon serves %s, not %s.';

    private const string CHANGED = '%s changed since the daemon started, so it stopped.';

    private const string STOPPED = 'The daemon stopped, as asked.';

    private const string UNREAD = 'The daemon could not read the request.';

    private const string UNFORKED = 'The daemon could not fork a child for the request.';

    private const string OTHER_USER = 'The daemon serves only its own user.';

    /** The longest wait between two looks at the children, in microseconds. */
    private const int LOOK = Seconds::MICROSECONDS;

    private const int NANOSECONDS_PER_MICROSECOND = Seconds::NANOSECONDS / Seconds::MICROSECONDS;

    private Listener|NotGiven $listener;

    private readonly Children $children;

    private int|float $last;

    /** @param Closure(ServeRequest, Connection): int $run runs a request's command line in a child: its exit code */
    public function __construct(
        private readonly ServeSocket $socket,
        private readonly ServeIdentity $started,
        private readonly Seconds $idle,
        private readonly Closure $run,
        Forking $forking,
        private readonly Seconds $request,
    ) {
        $this->listener = NotGiven::value();
        $this->children = new Children($forking);
        $this->last = hrtime(as_number: true);
    }

    /** Serves each request until it stops; or why it could not start. */
    public function served(): CannotJudge|NotGiven
    {
        $listener = $this->socket->listened();

        if ($listener instanceof CannotJudge) {
            return $listener;
        }

        $this->listener = $listener;

        while ($this->listener instanceof Listener || ! $this->children->areNone()) {
            $this->last = $this->children->reaped() ? hrtime(as_number: true) : $this->last;
            $this->looked();
        }

        return NotGiven::value();
    }

    /** One wait for a request or a client gone, and what follows it; the socket closed once the daemon has idled. */
    private function looked(): void
    {
        $left = $this->idleLeft();

        if ($this->children->areNone() && $left === 0) {
            $this->closed();

            return;
        }

        $wait = $this->children->areNone() ? min($left, self::LOOK) : self::LOOK;

        [$waiting, $connections] = Listener::ready($this->listener, $this->children->connections(), $wait);

        foreach ($connections as $child) {
            $this->children->stoppedWhereGone($child);
        }

        if ($waiting) {
            $this->accepted();
        }
    }

    /** How long the daemon may still wait for a request before it stops, in microseconds. */
    private function idleLeft(): int
    {
        $waited = intdiv((int) (hrtime(as_number: true) - $this->last), self::NANOSECONDS_PER_MICROSECOND);

        return max(0, $this->idle->microseconds() - $waited);
    }

    /** The request of the next client waiting, answered at once where it is refused, and run in a child otherwise. */
    private function accepted(): void
    {
        foreach ($this->listener instanceof Listener ? $this->listener->accepted() : [] as $connection) {
            $this->answered($connection);
        }
    }

    /** A client's request answered at once where it is refused, and run in a child otherwise. */
    private function answered(Connection $connection): void
    {
        $this->last = hrtime(as_number: true);
        $line = $connection->waitingAtMost((int) ceil($this->request->seconds()))->line();
        $request = $line instanceof NotGiven ? $line : ServeRequest::readFrom($line);
        $refused = match (true) {
            ! $this->socket->isOwnPeer($connection) => self::OTHER_USER,
            $request instanceof ServeRequest => $this->refusal($request),
            default => self::UNREAD,
        };
        $refused = $refused === '' && $request instanceof ServeRequest
            && ! $this->children->forked($request, $connection, $this->run)
            ? self::UNFORKED
            : $refused;

        if ($refused !== '') {
            $connection->sent(ServeFrame::refused($refused)->line());
        }
    }

    /** Why the daemon refuses a request, stopping where it asks or finds a change; nothing where it runs it. */
    private function refusal(ServeRequest $request): string
    {
        $identity = $request->identity();
        $changed = $identity->changedSince($this->started);

        return match (true) {
            ! $identity->isOfRootOf($this->started)
                => sprintf(self::OTHER_ROOT, $this->started->root(), $identity->root()),
            $request->stops() => $this->stopped(),
            $changed !== '' => $this->closing(sprintf(self::CHANGED, $changed)),
            default => '',
        };
    }

    /** The children stopped and the socket closed, as a request to stop asks: what the daemon answers it. */
    private function stopped(): string
    {
        $this->children->allStopped();

        return $this->closing(self::STOPPED);
    }

    /** The socket closed, as for this reason: the reason. */
    private function closing(string $why): string
    {
        $this->closed();

        return $why;
    }

    /** The socket closed and its file removed, so no request comes; the children running finish. */
    private function closed(): void
    {
        if ($this->listener instanceof Listener) {
            $this->socket->removed();
        }

        $this->listener = NotGiven::value();
    }
}
