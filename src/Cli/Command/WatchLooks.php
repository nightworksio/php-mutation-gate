<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use Closure;

use function count;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Watch\Watched;
use Psr\Clock\ClockInterface;

/**
 * The looks `watch` takes while a round runs (ADR-0010, ADR-0120): it looks
 * only once `local.watchPoll` has passed since its last look, so a round costs
 * at most one look a wait; the latest change one finds is kept, and once the
 * round is over, it is what the next round starts from, or one look more
 * finds what changed meanwhile. Where `local.cancelByUnit` is on, the round
 * reads which files have moved since it began (ADR-0146).
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
        if ($this->arrived instanceof NotGiven) {
            $this->looked();
        }

        return $this->arrived instanceof Watched;
    }

    /** Every file changed since the round began, by its name now and before, looking where a wait has passed. */
    public function moved(): Paths
    {
        $this->looked();

        return $this->arrived instanceof Watched ? $this->arrived->changesSince($this->seen)->paths() : Paths::none();
    }

    /** The files once the round is over: as the change it found left them, or as one more look finds them. */
    public function after(): Watched|CannotJudge
    {
        return $this->arrived instanceof Watched ? $this->arrived : ($this->look)($this->seen);
    }

    /** The latest look that found a change kept, none where it found none, looking where a wait has passed. */
    private function looked(): void
    {
        if ($this->every->outlasts(Seconds::between($this->since, $this->clock->now()))) {
            return;
        }

        $this->since = $this->clock->now();
        $now = ($this->look)($this->seen);
        $this->arrived = match (true) {
            ! $now instanceof Watched => $this->arrived,
            count($now->changesSince($this->seen)) > 0 => $now,
            default => NotGiven::value(),
        };
    }
}
