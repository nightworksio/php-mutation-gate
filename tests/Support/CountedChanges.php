<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Port\ChangeSource;

/** Another change source, counting each revision it is asked what changed from. */
final class CountedChanges implements ChangeSource
{
    /** @var list<string> each revision asked what changed from, by its name, in the order asked */
    private array $asked = [];

    public function __construct(private readonly ChangeSource $changes)
    {
    }

    public function changesSince(Revision $base): Changes|CannotTell
    {
        return $this->changes->changesSince($base);
    }

    public function changesFrom(Revision $commit): Changes|CannotTell
    {
        $this->asked[] = $commit->name();

        return $this->changes->changesFrom($commit);
    }

    /** @return list<string> each revision asked what changed from, by its name, in the order asked */
    public function askedFrom(): array
    {
        return $this->asked;
    }

    public function judged(Revision $commit): JudgedCommit|CannotTell
    {
        return $this->changes->judged($commit);
    }

    public function readable(JudgedCommit $judged): Revision|CannotTell
    {
        return $this->changes->readable($judged);
    }

    public function fingerprints(): Fingerprints|CannotTell
    {
        return $this->changes->fingerprints();
    }

    public function unstaged(): Paths|CannotTell
    {
        return $this->changes->unstaged();
    }

    /** @return ByPath<Instant>|CannotTell */
    public function lastChanged(Paths $paths): ByPath|CannotTell
    {
        return $this->changes->lastChanged($paths);
    }

    public function fileAt(Path $path, Revision $revision): Contents|Missing|CannotTell
    {
        return $this->changes->fileAt($path, $revision);
    }

    /** @return ByPath<Contents|Missing>|CannotTell */
    public function filesAt(Paths $paths, Revision $revision): ByPath|CannotTell
    {
        return $this->changes->filesAt($paths, $revision);
    }
}
