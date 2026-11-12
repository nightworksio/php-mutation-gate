<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use Closure;

use function is_int;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Watch\NoticesChanges;
use NightWorksIO\MutationGate\Core\Watch\Unwatched;

use function sprintf;

/**
 * The waits `watch` takes between two looks at the files once a round is
 * over, and whether to look while one runs (ADR-0010, ADR-0120, ADR-0133
 * decisions 7 and 8). Where `outbreak serve` watches the trees and the test
 * directories natively, a wait ends once a change it watched settled, and
 * after `local.watchPoll` at the latest, and a running round looks only once
 * it told of a change; every wait is `local.watchPoll` long where no daemon
 * serves the project,
 * where it watches nothing or stops answering, or where a look finds a change
 * it did not tell of within one more wait, and it says so once.
 *
 * @internal
 */
final class WatchWaits
{
    private const string UNWATCHED = 'outbreak serve watches no file (%s), so watch looks at the files every %s.';

    private const string UNANSWERED = 'outbreak serve stopped answering (%s), so watch looks at the files every %s.';

    private const string UNTOLD
        = 'outbreak serve did not tell of a change a look found, so watch looks at the files every %s.';

    private int $seen = 0;

    private bool $told = false;

    /**
     * @param Closure(Seconds): bool $waited waits this long, and says whether to go on watching
     * @param list<string>          $directories
     * @param Closure(string): void $said
     */
    private function __construct(
        private readonly Closure $waited,
        private NoticesChanges|NotGiven $notices,
        private readonly array $directories,
        private readonly Seconds $every,
        private readonly Closure $said,
    ) {
    }

    /**
     * The waits between looks, the daemon's watch armed on these directories
     * as `watch` starts, where one serves the project.
     *
     * @param Closure(Seconds): bool $waited waits this long, and says whether to go on watching
     * @param list<string>          $directories
     * @param Closure(string): void $said
     */
    public static function armed(
        Closure $waited,
        NoticesChanges|NotGiven $notices,
        array $directories,
        Seconds $every,
        Closure $said,
    ): self {
        $waits = new self($waited, $notices, $directories, $every, $said);
        $now = $notices instanceof NoticesChanges ? $notices->changed($directories, 0, Seconds::none()) : $notices;

        if (is_int($now)) {
            $waits->seen = $now;
        } elseif ($now instanceof Unwatched) {
            $waits->dropped($now);
        } else {
            $waits->notices = NotGiven::value();
        }

        return $waits;
    }

    /** Waits until the next look, and says whether to go on watching. */
    public function waited(): bool
    {
        return $this->asked($this->every) instanceof NotGiven ? ($this->waited)($this->every) : true;
    }

    /** Whether the daemon told of a change since it was last asked, at once; none where it watches nothing. */
    public function told(): bool|NotGiven
    {
        return $this->asked(Seconds::none());
    }

    /**
     * A look found a change: where the daemon told of none in the last wait
     * and still tells of none within one more, its watch is not trusted again.
     */
    public function found(): void
    {
        if ($this->notices instanceof NoticesChanges && ! $this->told && $this->asked($this->every) === false) {
            $this->dropped(NotGiven::value());
        }
    }

    /**
     * Whether the daemon told of a change past the last one it told of,
     * waiting this long for one; none where it watches nothing.
     */
    private function asked(Seconds $wait): bool|NotGiven
    {
        $now = $this->notices instanceof NoticesChanges
            ? $this->notices->changed($this->directories, $this->seen, $wait)
            : $this->notices;

        if (! is_int($now)) {
            $this->dropped($now);

            return NotGiven::value();
        }

        $this->told = $now > $this->seen;
        $this->seen = $now;

        return $this->told;
    }

    /** No more waits on the daemon, said once where it watched before. */
    private function dropped(Unwatched|NotWritten|NotGiven $why): void
    {
        if (! $this->notices instanceof NoticesChanges) {
            return;
        }

        $this->notices = NotGiven::value();
        $every = $this->every->text();
        ($this->said)(match (true) {
            $why instanceof Unwatched => sprintf(self::UNWATCHED, $why->why(), $every),
            $why instanceof NotWritten => sprintf(self::UNANSWERED, $why->why(), $every),
            default => sprintf(self::UNTOLD, $every),
        });
    }
}
