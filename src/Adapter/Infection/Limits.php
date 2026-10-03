<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_keys;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The seconds Infection allows each mutant: the standard mutant limit of the
 * time of the test classes that cover it, each counted once, under the
 * configured timeout, the cap. A mutant whose classes alone take the cap is
 * skipped.
 */
final readonly class Limits
{
    private function __construct(private CoverageMap $map, private JUnit $junit, private Seconds $cap)
    {
    }

    public static function of(CoverageMap $map, JUnit $junit, Seconds $cap): self
    {
        return new self($map, $junit, $cap);
    }

    /** The limit of a mutant that starts on this line, by the test classes that cover the line. */
    public function at(Path $file, Line $line): Seconds
    {
        $classes = [];

        foreach ($this->map->testsCovering($file, $line) as $test) {
            $classes[TestMethod::classOf($test)] = true;
        }

        $time = 0.0;

        foreach (array_keys($classes) as $class) {
            $time += $this->junit->classSeconds($class);
        }

        return MutantLimit::standard()->of(Seconds::of($time), $this->cap);
    }
}
