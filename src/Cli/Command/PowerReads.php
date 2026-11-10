<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_int;

use NightWorksIO\MutationGate\Adapter\Local\PowerSource;
use NightWorksIO\MutationGate\Core\Local\Power;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Watch\NoticesChanges;

/**
 * Where the machine draws its power from, as `watch` reads it (ADR-0120):
 * from its source again only once `outbreak serve` tells of a change of it
 * (ADR-0133, decision 8), and at each read where no daemon tells of one, it
 * watches none, or it stops answering.
 *
 * @internal
 */
final class PowerReads
{
    private Power|NotGiven $last;

    private int $seen = 0;

    private function __construct(
        private readonly PowerSource $source,
        private NoticesChanges|NotGiven $notices,
        private readonly Seconds $every,
    ) {
        $this->last = NotGiven::value();
    }

    /** Reads of this source, told of its changes by this daemon, waiting this long at most for one. */
    public static function of(PowerSource $source, NoticesChanges|NotGiven $notices, Seconds $every): self
    {
        return new self($source, $notices, $every);
    }

    /** Where the machine draws its power from now. */
    public function now(): Power
    {
        $last = $this->last;

        if ($this->asked(Seconds::none()) === false && $last instanceof Power) {
            return $last;
        }

        $this->last = $this->source->now();

        return $this->last;
    }

    /** Whether the daemon waited for a change of the power source, as long as a wait at most; it does not where none tells of one. */
    public function waited(): bool
    {
        return ! $this->asked($this->every) instanceof NotGiven;
    }

    /** Whether the daemon told of a change past the last one, waiting this long for one; none where none tells of one. */
    private function asked(Seconds $wait): bool|NotGiven
    {
        $now = $this->notices instanceof NoticesChanges ? $this->notices->powered($this->seen, $wait) : $this->notices;

        if (! is_int($now)) {
            $this->notices = NotGiven::value();

            return NotGiven::value();
        }

        $moved = $now > $this->seen;
        $this->seen = $now;

        return $moved;
    }
}
