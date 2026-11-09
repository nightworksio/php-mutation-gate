<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use Closure;

use function count;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Watch\Watched;
use Psr\Clock\ClockInterface;

/**
 * The looks `watch` takes while a round runs (ADR-0010, ADR-0120): before a
 * batch it looks only once `local.watchPoll` has passed since its last look,
 * so a round costs at most one look a wait; the change one finds is kept, and
 * once the round is over, it is what the next round starts from, or one look
 * more finds what changed meanwhile.
 *
 * @internal
 */
final class WatchLooks
{
    private Watched|NotGiven $arrived;

    private DateTimeImmutable $since;

    /** @param Closure(Watched): (Watched|CannotJudge) $look */
    public function __construct(
        private readonly Closure $look,
        private readonly ClockInterface $clock,
        private readonly Seconds $every,
        private readonly Watched $seen,
    ) {
        $this->arrived = NotGiven::value();
        $this->since = $clock->now();
    }

    /** Whether a change arrived since the round began, looking where a wait has passed since the last look. */
    public function arrived(): bool
    {
        $waited = ! $this->every->outlasts(Seconds::between($this->since, $this->clock->now()));

        if ($this->arrived instanceof NotGiven && $waited) {
            $this->since = $this->clock->now();
            $now = ($this->look)($this->seen);
            $this->arrived = $now instanceof Watched && count($now->changesSince($this->seen)) > 0
                ? $now
                : NotGiven::value();
        }

        return $this->arrived instanceof Watched;
    }

    /** The files once the round is over: as the change it found left them, or as one more look finds them. */
    public function after(): Watched|CannotJudge
    {
        return $this->arrived instanceof Watched ? $this->arrived : ($this->look)($this->seen);
    }

}
