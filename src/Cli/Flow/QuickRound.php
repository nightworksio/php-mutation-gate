<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * A quick round of `watch` (ADR-0146): the files changed since the round
 * before, whose coverage it takes from that round's map and the static graph
 * instead of measuring it again.
 *
 * @internal
 */
final readonly class QuickRound
{
    private function __construct(private Paths $changed)
    {
    }

    /** The round after one that left its map, with each path these changes name, by its name now and before. */
    public static function after(Changes $changes): self
    {
        $paths = Paths::none();

        foreach ($changes as $change) {
            $paths = $paths->with($change->path())->with($change->previousPath());
        }

        return new self($paths);
    }

    /** The files changed since the round before, by their names now and before. */
    public function changed(): Paths
    {
        return $this->changed;
    }
}
