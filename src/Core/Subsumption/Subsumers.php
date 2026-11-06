<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Subsumption;

use function array_flip;
use function array_key_exists;
use function array_map;

use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * Which of the mutants a run offers subsume which (ADR-0079): in a file a
 * run with a full kill matrix learned of, the one it learned subsumes each,
 * where that one is offered too; in every other file, by the rule that a
 * mutant of an expression subsumes the mutant that negates it (see
 * NegationRule). A mutant another offered one subsumes is held back until
 * the others have run.
 *
 * @internal
 */
final readonly class Subsumers
{
    /** @param array<string, array{MutantId, list<MutantId>}> $held each held mutant and its subsumers, by its key */
    private function __construct(private array $held)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Which of these offered mutants another offered one subsumes: as a run
     * learned it, in a file one did, and by the rule for negations elsewhere.
     */
    public static function among(Subsumption $learned, Mutant ...$offered): self
    {
        $offeredIds = array_flip(array_map(static fn(Mutant $mutant): string => $mutant->id()->key(), $offered));
        $unlearned = [];

        foreach ($offered as $mutant) {
            $unlearned = $learned->knows($mutant->location()->file()) ? $unlearned : [...$unlearned, $mutant];
        }

        $held = [];

        foreach ($offered as $mutant) {
            $by = $learned->subsumerOf($mutant->location()->file(), $mutant->id());
            $held += $by instanceof MutantId && array_key_exists($by->key(), $offeredIds)
                ? [$mutant->id()->key() => [$mutant->id(), [$by]]]
                : [];
        }

        return new self($held + NegationRule::among(...$unlearned));
    }

    /** These, and the mutants another run's offer holds back. */
    public function and(self $more): self
    {
        return new self($this->held + $more->held);
    }

    public function holds(MutantId $mutant): bool
    {
        return array_key_exists($mutant->key(), $this->held);
    }

    /**
     * The mutant whose kill by a test each held mutant's kill is inferred
     * from: a subsumer a test killed, or a held subsumer whose kill is
     * inferred in turn; a held mutant none infers is left out.
     *
     * @return array<string, MutantId> by the held mutant's id's key
     */
    public function inferredFrom(Mutants $judged): array
    {
        $killed = [];

        foreach ($judged as $mutant) {
            $killed += $mutant->status() === MutantStatus::Killed ? [$mutant->id()->key() => true] : [];
        }

        $inferred = [];

        foreach ($this->held as $key => [$mutant]) {
            $by = $this->killerOf($mutant, $killed, []);
            $inferred += $by instanceof MutantId ? [$key => $by] : [];
        }

        return $inferred;
    }

    /**
     * The killed mutant a held one's kill follows from, by its subsumers in
     * turn; none where none does, or where the chain comes back on itself.
     *
     * @param array<string, true> $killed  the keys of the mutants a test killed
     * @param array<string, true> $visited the keys of the held mutants already on the chain
     */
    private function killerOf(MutantId $held, array $killed, array $visited): MutantId|NotGiven
    {
        foreach ($this->held[$held->key()][1] as $subsumer) {
            $key = $subsumer->key();
            $by = match (true) {
                array_key_exists($key, $killed) => $subsumer,
                array_key_exists($key, $this->held) && ! array_key_exists($key, $visited)
                    => $this->killerOf($subsumer, $killed, [...$visited, $held->key() => true]),
                default => NotGiven::value(),
            };

            if ($by instanceof MutantId) {
                return $by;
            }
        }

        return NotGiven::value();
    }
}
