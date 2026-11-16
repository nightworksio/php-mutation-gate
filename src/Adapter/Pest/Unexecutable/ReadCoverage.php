<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Unexecutable;

use function array_map;
use function array_push;
use function array_values;

use NightWorksIO\MutationGate\Adapter\Pest\TestFiles;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoveredLine;
use NightWorksIO\MutationGate\Core\Coverage\TimedTest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Php\Codebase;
use NightWorksIO\MutationGate\Core\Php\Source;
use NightWorksIO\MutationGate\Core\Php\ValueDeclaration;
use NightWorksIO\MutationGate\Core\Php\ValueDeclarations;
use NightWorksIO\MutationGate\Core\Test\TestId;
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

    /** A map with the lines of each value these files declare covered so, built at once. */
    public function onto(Paths $files, CoverageMap $map): CoverageMap
    {
        $lines = [];
        $timed = [];

        foreach ($files as $file) {
            $source = $this->codebase->source($file);

            foreach ($source instanceof Source ? ValueDeclarations::in($source) : [] as $declared) {
                $tests = $this->reading($file, $declared);
                array_push($lines, ...$this->covered($file, $declared, $tests));
                $timed += $this->timed($tests, $map);
            }
        }

        return $map->besides(...$lines)->timedEach(...array_values($timed));
    }

    /** The tests one run judges the mutants of one value by; none where no run can. */
    private function reading(Path $file, ValueDeclaration $declared): TestIds
    {
        $run = $this->selector->choose($declared->symbol(), $file)->oneRun();

        return $run instanceof Paths ? $this->tests->holding($run, $this->map) : TestIds::none();
    }

    /**
     * How long each of these tests took, as the map of every file timed it,
     * where a map does not time it already, by id.
     *
     * @return array<string, TimedTest>
     */
    private function timed(TestIds $tests, CoverageMap $timing): array
    {
        $timed = [];

        foreach ($tests as $test) {
            $duration = $this->map->durations()->of($test);

            if ($duration instanceof Seconds && ! $timing->durations()->of($test) instanceof Seconds) {
                $timed[$test->value()] = TimedTest::of($test->value(), $duration->seconds());
            }
        }

        return $timed;
    }

    /**
     * Each line of one value, covered by these tests; none where there are none.
     *
     * @return list<CoveredLine>
     */
    private function covered(Path $file, ValueDeclaration $declared, TestIds $tests): array
    {
        $ids = array_map(static fn(TestId $test): string => $test->value(), [...$tests]);
        $lines = [];

        foreach ($ids === [] ? [] : range($declared->first()->number(), $declared->last()->number()) as $line) {
            $lines[] = CoveredLine::of($file, $line, ...$ids);
        }

        return $lines;
    }
}
