<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cluster;

/** The rule that put survivors in one cluster (ADR-0022, decision 15). */
enum ClusterKind: string
{
    /** Their changes overlap within one statement, so one boundary test kills them all. */
    case Expression = 'expression';
    /** They are of one family, in one function, and judged by exactly the same tests. */
    case Gap = 'gap';

    private const string GAP
        = 'They change one function the same way and the same tests judge them, so one assertion may kill them all.';

    /** The rule, as a report names it. */
    public function label(): string
    {
        return match ($this) {
            self::Expression => 'one expression',
            self::Gap => 'one gap',
        };
    }

    /** Why one test may kill every member; a gap is told more loosely, since its rule can group too much. */
    public function text(): string
    {
        return match ($this) {
            self::Expression => 'They change one expression, so one test that pins its result kills them all.',
            self::Gap => self::GAP,
        };
    }
}
