<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_keys;
use function min;

use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestMethod;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * The seconds Infection allows each mutant: 5 s for PHPUnit to start, plus
 * five times the time of the test classes that cover it, each counted once,
 * and never more than the configured timeout, the cap. A mutant whose
 * classes alone take the cap is skipped.
 */
final readonly class Limits
{
    /** What Infection allows PHPUnit to start in, in seconds. */
    private const float BOOTSTRAP = 5.0;

    /** How many times its covering tests' own time Infection allows a mutant. */
    private const int FACTOR = 5;

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

        return Seconds::of(min(self::BOOTSTRAP + self::FACTOR * $time, $this->cap->seconds()));
    }
}
