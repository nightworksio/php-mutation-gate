<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function count;

use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Looked;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\Local\LocalSwitch;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Watch\NoticesChanges;
use NightWorksIO\MutationGate\Core\Watch\Watched;

/**
 * How `watch` finds a change to the files it watches: through version
 * control's status where `local.watchStatus` lets it, every file hashed
 * where it does not, looked at as `outbreak serve` tells of a change where it
 * serves the project (ADR-0120, ADR-0133).
 *
 * @internal
 */
final readonly class WatchedChanges
{
    public function __construct(private Composed $composed)
    {
    }

    /** What `outbreak serve` tells of the changes it watches for, where it serves the project. */
    public function notices(): NoticesChanges|NotGiven
    {
        $engine = $this->composed->adapters->maps;

        return $engine instanceof Outbreak ? $engine->notices() : NotGiven::value();
    }

    /** The watched files as they are on disk now. */
    public function look(Watched $seen): Watched|CannotJudge
    {
        $changes = $this->composed->adapters->changes;
        $files = $this->composed->settings->local()->work()->isOn(LocalSwitch::WatchStatus)
            ? $changes->lookedSince($seen->looked())
            : $changes->fingerprints();
        $looked = $files instanceof Fingerprints ? Looked::whole($files) : $files;

        return $looked instanceof CannotTell ? CannotJudge::because($looked->why()) : $seen->after($looked);
    }

    /** The files once a round is over: as a change during it left them, or as the next change leaves them. */
    public function after(Watched $seen, WatchLooks $looks, WatchWaits $waits): Watched|CannotJudge|NotGiven
    {
        $now = $looks->after();

        return $now instanceof Watched && count($now->changesSince($seen)) === 0
            ? $this->next($now, $waits)
            : $now;
    }

    /** The files as they are once one changes, or nothing where the wait says to stop first. */
    private function next(Watched $seen, WatchWaits $waits): Watched|CannotJudge|NotGiven
    {
        while ($waits->waited()) {
            $now = $this->look($seen);

            if (! $now instanceof Watched) {
                return $now;
            }

            if (count($now->changesSince($seen)) > 0) {
                $waits->found();

                return $now;
            }
        }

        return NotGiven::value();
    }
}
