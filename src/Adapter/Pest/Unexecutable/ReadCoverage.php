<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function count;

use NightWorksIO\MutationGate\Adapter\Pest\TestFiles;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Codebase;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Php\ValueDeclaration;
use NightWorksIO\MutationGate\Core\Php\ValueDeclarations;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function range;

/**
 * The lines of each value some files declare, covered by the tests of the
 * test files one run judges its mutants by (see Choice), each timed as the
 * map of every file timed it, where it did not already, so Pest runs those
 * mutants as it runs any covered one. A value whose reads the scan cannot
 * follow, or that nothing reads, is left as the map had it, and a trial
 * judges its mutants (ADR-0037).
 *
 * @internal
 */
final readonly class ReadCoverage
{
    public function __construct(
        private Selector $selector,
        private Codebase $codebase,
        private TestFiles $tests,
        private CoverageMap $map,
    ) {
    }

    /** A map with the lines of each value these files declare covered so. */
    public function onto(Paths $files, CoverageMap $map): CoverageMap
    {
        $read = $map;

        foreach ($files as $file) {
            $source = $this->codebase->source($file);

            foreach ($source instanceof Source ? ValueDeclarations::in($source) : [] as $declared) {
                $read = $this->reading($read, $file, $declared);
            }
        }

        return $read;
    }

    /**
     * A map with the lines of one value covered by the tests one run judges
     * its mutants by, each timed; the map as it is where no run can.
     */
    private function reading(CoverageMap $map, Path $file, ValueDeclaration $declared): CoverageMap
    {
        $run = $this->selector->choose($declared->symbol(), $file)->oneRun();
        $tests = $run instanceof Paths ? $this->tests->holding($run, $this->map) : TestIds::none();

        if (count($tests) === 0) {
            return $map;
        }

        $read = $map;

        foreach (range($declared->first()->number(), $declared->last()->number()) as $line) {
            $read = $read->covered($file, Line::of($line), ...$tests);
        }

        return $read->timedEach(...$this->timed($tests, $map));
    }

    /**
     * How long each of these tests took, as the map of every file timed it,
     * where a map does not time it already.
     *
     * @return list<TimedTest>
     */
    private function timed(TestIds $tests, CoverageMap $timing): array
    {
        $timed = [];

        foreach ($tests as $test) {
            $duration = $this->map->durations()->of($test);

            if ($duration instanceof Seconds && ! $timing->durations()->of($test) instanceof Seconds) {
                $timed[] = TimedTest::of($test->value(), $duration->seconds());
            }
        }

        return $timed;
    }
}
