<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Subsumption;

use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * Which mutants subsume which where no run learned it (ADR-0079): of the
 * mutants that change the same lines alike, each that changes only tokens
 * of an expression another negates subsumes that other. Where its change
 * makes the expression differ, the expression is the opposite of what it
 * was, which is what the negation makes it everywhere. Of two that negate
 * the same expression, the one with the lower id subsumes the other.
 *
 * @internal
 */
final readonly class NegationRule
{
    /** What the mutants that change the same lines alike share. */
    private const string LINES = "%s\n%d\n%s";

    /**
     * Each mutant another of these subsumes, with every one that does.
     *
     * @return array<string, array{MutantId, list<MutantId>}> by the subsumed mutant's id's key
     */
    public static function among(Mutant ...$mutants): array
    {
        $alike = [];

        foreach ($mutants as $mutant) {
            $change = Change::of($mutant->mutation()->diff());
            $location = $mutant->location();
            $lines = sprintf(self::LINES, $location->file()->value(), $location->start()->number(), $change->removed());
            $alike[$lines][] = [$mutant->id(), $change->tokens()->differs(), Negation::in($change)];
        }

        $subsumed = [];

        foreach ($alike as $changes) {
            $subsumed += self::subsumedAmong($changes);
        }

        return $subsumed;
    }

    /**
     * Each of these changes of the same lines that negates an expression
     * another of them changes only within, with every one that does.
     *
     * @param  list<array{MutantId, array{int, int}, array{int, int}|NotGiven}> $changes
     * @return array<string, array{MutantId, list<MutantId>}>
     */
    private static function subsumedAmong(array $changes): array
    {
        $subsumed = [];

        foreach ($changes as [$negating, , $negated]) {
            $subsumers = $negated instanceof NotGiven ? [] : self::subsumersOf($negating, $negated, $changes);
            $subsumed += $subsumers === [] ? [] : [$negating->key() => [$negating, $subsumers]];
        }

        return $subsumed;
    }

    /**
     * The mutants of the same lines that subsume the one that negates these tokens.
     *
     * @param  array{int, int}                                                       $negated
     * @param  list<array{MutantId, array{int, int}, array{int, int}|NotGiven}> $changes
     * @return list<MutantId>
     */
    private static function subsumersOf(MutantId $negating, array $negated, array $changes): array
    {
        $subsumers = [];

        foreach ($changes as [$other, [$from, $to], $otherNegated]) {
            $within = $from >= $negated[0] && $to <= $negated[1];
            $itselfOrLaterTwin = $otherNegated === $negated && $other->value() >= $negating->value();
            $subsumers = $within && ! $itselfOrLaterTwin ? [...$subsumers, $other] : $subsumers;
        }

        return $subsumers;
    }
}
