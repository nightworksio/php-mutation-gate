<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_slice;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

use function strcmp;
use function usort;

/**
 * How many of a mutant's dependents a check analyses again against it
 * (ADR-0020, decision 7): the first in path order, so the same mutant
 * always sends the same files. A dependent past the cap is not analysed
 * against the mutant, which can leave a mutant unkilled; it never kills one.
 */
final readonly class DependentCap
{
    /**
     * Files analysed again per check: at the 0.02–0.06 s Psalm's language
     * server takes to analyse a file again, about a second, the time one
     * check by Mago, which analyses the whole project, takes.
     */
    private const int STANDARD = 25;

    private function __construct(private int $most)
    {
    }

    /** Twenty-five dependents. */
    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    /** These files, at most as many as the cap allows, the first in path order. */
    public function of(Paths $files): Paths
    {
        $ordered = [...$files];
        usort($ordered, static fn(Path $one, Path $other): int => strcmp($one->value(), $other->value()));

        return Paths::of(...array_slice($ordered, 0, $this->most));
    }
}
