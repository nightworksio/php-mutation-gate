<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sort;
use function sprintf;

/**
 * A unit's mutants as each shard that holds it judged them, merged into one
 * status each, where a run cuts its test files across its shards and each
 * shard judges its mutants by one part of them (ADR-0117). A kill in any
 * part wins, then a timeout; an own run's other answers, given by the one
 * shard that ran it, stand over a survivor; a mutant survives where every
 * part that reached it let it survive; and a status every part gives alike,
 * such as uncovered, merges to one. A shard's not-reached outcome is no
 * answer: a mutant no part reached cannot be judged, nor can parts that hold
 * other mutants than each other. Each mutant keeps the record of the first
 * shard, in their order, whose status it takes; the mutants of one shard are
 * its own.
 */
final readonly class MergedParts
{
    /** Each status a part may give, before every status it stands over. */
    private const array STANDING = [
        MutantStatus::Killed,
        MutantStatus::KilledByStaticAnalysis,
        MutantStatus::Errored,
        MutantStatus::TimedOut,
        MutantStatus::OutOfMemory,
        MutantStatus::FailsUnmutated,
        MutantStatus::Skipped,
        MutantStatus::Unjudged,
        MutantStatus::IgnoredByMarker,
        MutantStatus::Uncovered,
        MutantStatus::Survived,
    ];

    private const string OTHER_MUTANTS = <<<'SAID'
        The shards that judge %s by parts of the test files hold other mutants of it than each other,
        so they did not mutate the same code. Run every shard of one plan on the commit it was made on.
        SAID;

    private const string UNREACHED = <<<'SAID'
        No shard that judges %s by a part of the test files reached its mutant %s, so none judged it.
        Run every shard of one plan, and the verdict with that plan.
        SAID;

    /** The unit's mutants, each with the status that stands among its parts'. */
    public static function of(Path $unit, Mutants ...$parts): Mutants|CannotJudge
    {
        $parts = array_values($parts);

        if ($parts === [] || count($parts) === 1) {
            return $parts === [] ? Mutants::none() : $parts[0];
        }

        $outcomes = self::byId($unit, $parts);

        return $outcomes instanceof CannotJudge ? $outcomes : self::merged($unit, $outcomes);
    }

    /**
     * Each mutant's outcome in every part, by its id, in the order the first
     * part holds them; or why the parts cannot be merged.
     *
     * @param  non-empty-list<Mutants>          $parts
     * @return array<string, list<Mutant>>|CannotJudge
     */
    private static function byId(Path $unit, array $parts): array|CannotJudge
    {
        $outcomes = [];

        foreach ($parts as $part) {
            foreach ($part as $mutant) {
                $outcomes[$mutant->id()->value()][] = $mutant;
            }
        }

        $first = self::idsOf($parts[0]);

        foreach ($parts as $part) {
            if (self::idsOf($part) !== $first) {
                return CannotJudge::because(sprintf(self::OTHER_MUTANTS, $unit->value()));
            }
        }

        return $outcomes;
    }

    /**
     * Each mutant with the outcome that stands among its parts'; or why one cannot be judged.
     *
     * @param array<string, list<Mutant>> $outcomes
     */
    private static function merged(Path $unit, array $outcomes): Mutants|CannotJudge
    {
        $merged = [];

        foreach ($outcomes as $id => $parts) {
            $standing = self::standing($parts);

            if (! $standing instanceof Mutant) {
                return CannotJudge::because(sprintf(self::UNREACHED, $unit->value(), $id));
            }

            $merged[] = $standing;
        }

        return Mutants::of(...$merged);
    }

    /**
     * The outcome whose status stands over every other part's, the first
     * part's of those that give it; none where no part reached the mutant.
     *
     * @param list<Mutant> $parts
     */
    private static function standing(array $parts): Mutant|NotGiven
    {
        foreach (self::STANDING as $status) {
            foreach ($parts as $outcome) {
                if ($outcome->status() === $status) {
                    return $outcome;
                }
            }
        }

        return NotGiven::value();
    }

    /**
     * The ids of a part's mutants, in byte order.
     *
     * @return list<string>
     */
    private static function idsOf(Mutants $part): array
    {
        $ids = [];

        foreach ($part as $mutant) {
            $ids[] = $mutant->id()->value();
        }

        sort($ids);

        return $ids;
    }
}
