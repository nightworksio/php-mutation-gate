<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_key_exists;
use function array_values;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * The change `affected` is asked about (ADR-0020, decision 3): every change
 * since the commit the coverage map was measured at, and every change since
 * the ref asked for besides. A path both changed is taken as it changed since
 * the map's commit, which is what the map's lines are of; each change was
 * made since the revision its file is read at as it was.
 */
final readonly class AskedChanges
{
    /** @param array<string, array{Change, Revision}> $changes each change and the revision it changed since, by path */
    private function __construct(private array $changes)
    {
    }

    public static function of(Changes $sinceMap, Revision $measured, Changes $asked, Revision|NotGiven $base): self
    {
        $changes = [];

        foreach ($sinceMap as $change) {
            $changes[$change->path()->value()] = [$change, $measured];
        }

        if ($base instanceof NotGiven) {
            return new self($changes);
        }

        foreach ($asked as $change) {
            $path = $change->path()->value();
            $changes[$path] = array_key_exists($path, $changes) ? $changes[$path] : [$change, $base];
        }

        return new self($changes);
    }

    public function changes(): Changes
    {
        $changes = Changes::none();

        foreach ($this->changes as [$change]) {
            $changes = $changes->with($change);
        }

        return $changes;
    }

    /** @return list<array{Change, Revision}> each change, with the revision it was made since */
    public function since(): array
    {
        return array_values($this->changes);
    }
}
