<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Config\Definition\Fields;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Time\Day;

/** Equivalent mutants the config ignores (ADR-0008): `ignores.entries`, `ignores.maxDays`, `ignores.native`. */
final readonly class Ignores
{
    /** @param Listed<Ignored> $entries */
    public function __construct(private Listed $entries, private int|Absent $maxDays, private NativeMarkers $native)
    {
    }

    /** An `ignores.entries` entry: one mutant by its id, or a mutator in the paths a glob matches. */
    public static function entry(Fields $read, string $at): IgnoredMutant|IgnoredPattern|Invalid
    {
        $mutant = $read->optional('mutant', MutantId::class);
        $pattern = $read->has('path') || $read->has('mutator');
        $expires = $read->optional('expires', Day::class);

        return match (true) {
            $mutant instanceof MutantId && ! $pattern => IgnoredMutant::of($mutant, $read->string('reason'), $expires),
            $mutant instanceof Absent && $read->has('path') && $read->has('mutator') => IgnoredPattern::of(
                $read->string('path'),
                $read->string('mutator'),
                $read->string('reason'),
                $expires,
            ),
            default => Invalid::because(
                Problem::at($at, 'expected either mutant, or path and mutator, but not both'),
            ),
        };
    }

    /** @return Listed<Ignored> each an IgnoredMutant or an IgnoredPattern */
    public function entries(): Listed
    {
        return $this->entries;
    }

    /** How many days from a run every entry must expire within, or none, when entries may never expire. */
    public function maxDays(): int|Absent
    {
        return $this->maxDays;
    }

    public function native(): NativeMarkers
    {
        return $this->native;
    }
}
