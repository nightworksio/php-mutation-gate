<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_keys;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The seconds Infection allows each mutant, from the time of the test classes
 * that cover it, each counted once: patched, the standard mutant limit within
 * the bounds; unpatched, Infection's own under its `timeout`, `timeouts.most`,
 * where a mutant whose classes alone take it is skipped. Patched, it knows
 * too which mutants were stopped at their silence limit.
 */
final readonly class Limits
{
    private function __construct(
        private CoverageMap $map,
        private JUnit $junit,
        private LimitBounds $bounds,
        private PatchState $state,
        private Silenced $silenced,
    ) {
    }

    public static function of(CoverageMap $map, JUnit $junit, LimitBounds $bounds, PatchState $state): self
    {
        return new self($map, $junit, $bounds, $state, Silenced::none());
    }

    /** These limits, with the mutants a patched Infection stopped at their silence limit. */
    public function silencedAt(Silenced $silenced): self
    {
        return new self($this->map, $this->junit, $this->bounds, $this->state, $silenced);
    }

    /**
     * The silence limit the mutant Infection's log names so was stopped at,
     * by its original file as the log names it; none where it was not.
     */
    public function silenceOf(string $file, int $line, string $mutator, string $diff): Seconds|NotGiven
    {
        return $this->silenced->of($file, $line, $mutator, $diff);
    }

    /** The limit of a mutant that starts on this line, by the test classes that cover the line. */
    public function at(Path $file, Line $line): Seconds
    {
        $classes = [];

        foreach ($this->map->testsCovering($file, $line, $line) as $test) {
            $classes[TestMethod::classOf($test)] = true;
        }

        $time = 0.0;

        foreach (array_keys($classes) as $class) {
            $time += $this->junit->classSeconds($class);
        }

        return $this->state === PatchState::Applied
            ? MutantLimit::standard()->of(Seconds::of($time), $this->bounds)
            : MutantLimit::infections()->of(Seconds::of($time), LimitBounds::upTo($this->bounds->most()));
    }
}
