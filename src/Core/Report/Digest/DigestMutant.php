<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Digest;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Format\Visible;
use NightWorksIO\MutationGate\Core\Hint\Change;
use NightWorksIO\MutationGate\Core\Hint\Hint;
use NightWorksIO\MutationGate\Core\Report\Mutator;
use NightWorksIO\MutationGate\Core\Report\Outcome;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Scoring;

use function sprintf;

/**
 * One mutant of a JSON report as the digest reads it: where it is, its
 * mutator, what it came to, the gate's own judgement, whether it is on a
 * changed line, its diff and hint, and the tests the report points it at.
 *
 * @internal
 */
final readonly class DigestMutant
{
    /** What a mutant on a line the change touched says after its id. */
    public const string CHANGED = ' changed';

    /** What a judgement in a report must be. */
    public const string JUDGEMENT = 'a judgement';
    /** A survivor's line: its outcome, its line, its mutator, what it changed, its id, then what would catch it. */
    private const string LINE = '%s :%d %s `%s` -> `%s` id=%s%s | %s';

    private function __construct(private Node $entry)
    {
    }

    public static function of(Node $entry): self
    {
        return new self($entry);
    }

    /** @throws NotInShape */
    public function id(): string
    {
        return $this->entry->field('id')->text();
    }

    /** @throws NotInShape */
    public function file(): string
    {
        return $this->entry->field('file')->text();
    }

    /** @throws NotInShape */
    public function line(): int
    {
        return $this->entry->field('line')->integer();
    }

    /** @throws NotInShape */
    public function judgement(): MutantJudgement
    {
        $field = $this->entry->field('judgement');
        $judgement = MutantJudgement::tryFrom($field->text());

        return $judgement instanceof MutantJudgement ? $judgement : throw NotInShape::at($field->at(), self::JUDGEMENT);
    }

    /** @throws NotInShape */
    public function outcome(): Outcome
    {
        return Outcome::of($this->judgement());
    }

    /**
     * Whether the score counts it as not killed, as the HTML report lists its survivors.
     *
     * @throws NotInShape
     */
    public function survives(Uncovered $uncovered): bool
    {
        return $this->judgement()->scoring($uncovered) === Scoring::NotKilled;
    }

    /** @throws NotInShape */
    public function changed(): bool
    {
        return $this->entry->field('changedLine')->boolean();
    }

    /**
     * The mutant as one line, without its file, which a line before it names.
     *
     * @throws NotInShape
     */
    public function said(): string
    {
        $change = Change::of($this->diff());

        return sprintf(
            self::LINE,
            $this->outcome()->value,
            $this->line(),
            Visible::line(Mutator::short($this->mutator())),
            Visible::line($change->replaced()),
            Visible::line($change->mutated()),
            Visible::line($this->id()),
            $this->changed() ? self::CHANGED : '',
            Visible::line(Hint::lead($this->hint())),
        );
    }

    /** @throws NotInShape */
    public function mutator(): string
    {
        return $this->entry->field('mutator')->text();
    }

    /**
     * Its diff; none where the report keeps none, as for a kill a proof carried.
     *
     * @throws NotInShape
     */
    public function diff(): string
    {
        $diff = $this->entry->field('diff');

        return $diff->isPresent() ? $diff->text() : '';
    }

    /** @throws NotInShape */
    public function hint(): string
    {
        return $this->entry->field('hint')->text();
    }

    /**
     * The places in the report's list of tests of those that cover it.
     *
     * @return list<int>
     *
     * @throws NotInShape
     */
    public function coveredBy(): array
    {
        return $this->entry->field('coveredBy')->integers();
    }

    /**
     * The places in the report's list of tests of those that killed it.
     *
     * @return list<int>
     *
     * @throws NotInShape
     */
    public function killedBy(): array
    {
        return $this->entry->field('killedBy')->integers();
    }

    /**
     * What the report says beside it, by key, where it says it: why it came to what it did, its cluster, and how to
     * run it again.
     *
     * @throws NotInShape
     */
    public function optional(string $key): string
    {
        $field = $this->entry->field($key);

        return $field->isPresent() ? $field->text() : '';
    }
}
