<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_filter;
use function array_values;

use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * What a coverage run recorded beside its report: each test it timed, in the
 * order it timed them, and what each process's tests loaded (ADR-0115). The
 * report leaves out a test that ran no line it measures, so the gate adds
 * these to the report's map.
 *
 * @internal
 */
final readonly class RecordedBeside
{
    /** @param list<TimedTest> $timed */
    private function __construct(private array $timed, private LoadedPackages $loads)
    {
    }

    public static function of(LoadedPackages $loads, TimedTest ...$timed): self
    {
        return new self(array_values($timed), $loads);
    }

    /** Nothing recorded. */
    public static function nothing(): self
    {
        return new self([], LoadedPackages::unrecorded());
    }

    /** @return list<TimedTest> each test the run timed, in the order it timed them */
    public function timed(): array
    {
        return $this->timed;
    }

    public function loads(): LoadedPackages
    {
        return $this->loads;
    }

    /**
     * A report's map knowing every test this times that the map does not
     * time itself, with these seconds for it, and what the tests loaded.
     */
    public function onto(CoverageMap $map): CoverageMap
    {
        $unknown = array_filter(
            $this->timed,
            static fn(TimedTest $timed): bool => $map->durationOf($timed->test()) instanceof Unmeasured,
        );

        return $map->timedEach(...$unknown)->loading($this->loads);
    }
}
