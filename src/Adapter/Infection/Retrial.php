<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function array_key_exists;
use function array_values;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Reason;

use function sprintf;

/**
 * A run again for Infection, as survivor confirmation asks (ADR-0008): each
 * mutant runs again, narrowed to its file and mutator, and is matched back
 * by the gate's id.
 */
final readonly class Retrial
{
    /** Why a mutant run again is unjudged: the run made none with its id. */
    public const string NOT_FOUND_AGAIN = 'Run again, Infection made no mutant with this id.';

    private function __construct()
    {
    }

    public static function of(): self
    {
        return new self();
    }

    /**
     * Each file and mutator the mutants need a run of, once each.
     *
     * @return list<array{Path, string}>
     */
    public function runs(Mutants $mutants): array
    {
        $runs = [];

        foreach ($mutants as $mutant) {
            $file = $mutant->location()->file();
            $mutator = $mutant->mutation()->mutator();
            $key = sprintf('%s %s', $file->value(), $mutator);

            if (! array_key_exists($key, $runs)) {
                $runs[$key] = [$file, $mutator];
            }
        }

        return array_values($runs);
    }

    /**
     * The mutants in the order asked, each replaced by what the runs again
     * reported of it, or unjudged where they made no mutant with its id.
     */
    public function matched(Mutants $asked, Mutants $again): Mutants
    {
        $found = [];

        foreach ($again as $mutant) {
            $found[$mutant->id()->key()] = $mutant;
        }

        $matched = Mutants::none();

        foreach ($asked as $mutant) {
            $key = $mutant->id()->key();
            $matched = $matched->with(array_key_exists($key, $found) ? $found[$key] : $this->unjudged($mutant));
        }

        return $matched;
    }

    private function unjudged(Mutant $mutant): Mutant
    {
        return Mutant::of(
            $mutant->id(),
            $mutant->nativeId(),
            $mutant->location(),
            $mutant->mutation(),
            MutantStatus::Unjudged,
            $mutant->duration(),
        )->because(Reason::that(self::NOT_FOUND_AGAIN));
    }
}
