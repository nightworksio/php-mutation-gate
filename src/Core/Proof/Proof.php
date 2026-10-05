<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKills;
use NightWorksIO\MutationGate\Core\Test\TestIds;

/**
 * A unit's result, stored under the content key of everything its verdict
 * read, with the run that established it. It holds the mutants a runner
 * reported in full, with their statuses before ignores and floors apply, and
 * the kills a ledger proved, which it keeps no more of than a kill needs.
 * Beside its key it records the digests of its inputs, which say whether its
 * result can stand for other code (ADR-0008, decision 1); a proof of an
 * earlier ledger format records none. A proof of a held unit also records the
 * holding tests that run it, which judge its mutants.
 */
final readonly class Proof
{
    private function __construct(
        private Digest $key,
        private Path $unit,
        private Mutants $reported,
        private ProvedKills $kills,
        private Run $run,
        private Inputs|Undigested $inputs,
        private TestIds $judging,
    ) {
    }

    /** The proof of a run: every mutant of the unit, as its runner reported them. */
    public static function of(Digest $key, Path $unit, Mutants $reported, Run $run): self
    {
        return new self($key, $unit, $reported, ProvedKills::none(), $run, Undigested::proof(), TestIds::none());
    }

    /** A proof as a ledger holds it: its killed mutants as kills, and every other in full. */
    public static function held(Digest $key, Path $unit, Mutants $reported, ProvedKills $kills, Run $run): self
    {
        return new self($key, $unit, $reported, $kills, $run, Undigested::proof(), TestIds::none());
    }

    /** This proof, recording these digests of its inputs. */
    public function withInputs(Inputs|Undigested $inputs): self
    {
        return clone($this, ['inputs' => $inputs]);
    }

    /** This proof, of a held unit these of whose holding tests run it. */
    public function judgedBy(TestIds $judging): self
    {
        return clone($this, ['judging' => $judging]);
    }

    /** The holding tests that run a held unit; none for a unit the whole suite judges, or a proof that names none. */
    public function judging(): TestIds
    {
        return $this->judging;
    }

    /** The digests of its inputs; none for a proof of an earlier ledger format. */
    public function inputs(): Inputs|Undigested
    {
        return $this->inputs;
    }

    public function key(): Digest
    {
        return $this->key;
    }

    public function unit(): Path
    {
        return $this->unit;
    }

    /** The mutants a runner reported in full. */
    public function reported(): Mutants
    {
        return $this->reported;
    }

    /** The kills a ledger proved. */
    public function kills(): ProvedKills
    {
        return $this->kills;
    }

    /** The ids of every mutant it holds, in full or as a kill. */
    public function ids(): MutantIds
    {
        $ids = [];

        foreach ($this->reported as $mutant) {
            $ids[] = $mutant->id();
        }

        foreach ($this->kills as $kill) {
            $ids[] = $kill->id();
        }

        return MutantIds::of(...$ids);
    }

    public function run(): Run
    {
        return $this->run;
    }

    /**
     * The mutants whose status here differs from theirs in those, or that only
     * one of the two holds: the answers two runs of the same code do not agree
     * on. A kill a ledger proved is killed, and so is a kill by static
     * analysis.
     */
    public function disagreeingWith(Mutants|self $those): MutantIds
    {
        $theirs = $those instanceof self ? $those->answers() : $this->answersOf($those);
        $differ = [];

        foreach ($this->answers() as $key => [$id, $status]) {
            if (! array_key_exists($key, $theirs) || $theirs[$key][1] !== $status) {
                $differ[] = $id;
            }

            unset($theirs[$key]);
        }

        foreach ($theirs as [$id]) {
            $differ[] = $id;
        }

        return MutantIds::of(...$differ);
    }

    /** @return array<string, array{MutantId, MutantStatus}> each mutant's id and status, by its id's key */
    private function answers(): array
    {
        $answers = $this->answersOf($this->reported);

        foreach ($this->kills as $kill) {
            $answers[$kill->id()->key()] = [$kill->id(), MutantStatus::Killed];
        }

        return $answers;
    }

    /** @return array<string, array{MutantId, MutantStatus}> each mutant's id and status, by its id's key */
    private function answersOf(Mutants $mutants): array
    {
        $answers = [];

        foreach ($mutants as $mutant) {
            $answers[$mutant->id()->key()] = [$mutant->id(), $mutant->status()->answer()];
        }

        return $answers;
    }
}
