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
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A retry for Infection (ADR-0008): each mutant runs again, narrowed to its
 * file and mutator, and is matched back by the gate's id. A timed-out or
 * skipped mutant runs again only where the cap decided its limit: where the
 * formula decided it, a higher cap would not change it.
 */
final readonly class Retrial
{
    private const string NOT_FOUND_AGAIN = 'Run again, Infection made no mutant with this id.';

    private function __construct(private Seconds $cap)
    {
    }

    /** The retry of mutants whose limits came from this cap. */
    public static function under(Seconds $cap): self
    {
        return new self($cap);
    }

    /** Whether a mutant runs again: any that did not time out or skip, and one that did so at the cap. */
    public function takes(Mutant $mutant): bool
    {
        $limit = $mutant->limit();

        return ! $mutant->status()->ranOutOfTime()
            || ($limit instanceof Seconds && $limit->seconds() >= $this->cap->seconds());
    }

    /**
     * Each file and mutator the taken mutants need a run of, once each.
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

            if ($this->takes($mutant) && ! array_key_exists($key, $runs)) {
                $runs[$key] = [$file, $mutator];
            }
        }

        return array_values($runs);
    }

    /**
     * The mutants in the order asked: each one taken is replaced by what the
     * runs again reported of it, or is unjudged where they made no mutant with
     * its id. The rest are as they were.
     */
    public function matched(Mutants $asked, Mutants $again): Mutants
    {
        $found = [];

        foreach ($again as $mutant) {
            $found[$mutant->id()->value()] = $mutant;
        }

        $matched = Mutants::none();

        foreach ($asked as $mutant) {
            $matched = $matched->with(match (true) {
                ! $this->takes($mutant) => $mutant,
                array_key_exists($mutant->id()->value(), $found) => $found[$mutant->id()->value()],
                default => $this->unjudged($mutant),
            });
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
