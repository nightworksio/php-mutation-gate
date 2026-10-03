<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function array_key_exists;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;

/**
 * The coverage map the adapter's last mutation run read: PHPUnit's run of
 * some tests under coverage, or the map another job handed on. A run again
 * that reads the same reads it as it is, so it runs no suite under coverage a
 * second time. Each mutation run forgets it and reads its own, so coverage
 * never outlives an edit between runs, as `watch` makes.
 */
final class HeldCoverage
{
    /** @var array<string, CoverageMap> the map by what it was read from: nothing yet, or one entry */
    private array $holding = [];

    /** Holds nothing, so the next run reads its map afresh. */
    public function forget(): void
    {
        $this->holding = [];
    }

    /**
     * The map read from this, unless it already holds it.
     *
     * @param Closure(): (CoverageMap|CannotJudge) $reading
     */
    public function readFrom(string $what, Closure $reading): CoverageMap|CannotJudge
    {
        if (array_key_exists($what, $this->holding)) {
            return $this->holding[$what];
        }

        $read = $reading();
        $this->holding = $read instanceof CoverageMap ? [$what => $read] : [];

        return $read;
    }
}
