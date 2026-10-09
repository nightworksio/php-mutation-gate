<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_key_exists;
use function array_values;
use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use Traversable;

/**
 * What an analyser answers of mutants it checked in one go, each in the
 * place of the check it answers: the findings, out of its scope, or why the
 * check could not run.
 *
 * @implements IteratorAggregate<int, Findings|OutOfScope|CannotJudge>
 */
final readonly class CheckAnswers implements Countable, IteratorAggregate
{
    /** Why a check with no answer in its place cannot judge. */
    private const string UNANSWERED = 'The analyser gave no answer to the check.';

    /** @param list<Findings|OutOfScope|CannotJudge> $answers */
    private function __construct(private array $answers)
    {
    }

    public static function of(Findings|OutOfScope|CannotJudge ...$answers): self
    {
        return new self(array_values($answers));
    }

    public function count(): int
    {
        return count($this->answers);
    }

    /** The answer to the check in this place; one that cannot judge where the analyser gave none. */
    public function at(int $place): Findings|OutOfScope|CannotJudge
    {
        return array_key_exists($place, $this->answers)
            ? $this->answers[$place]
            : CannotJudge::because(self::UNANSWERED);
    }

    /** The answer to the first check, which a batch of one check holds; one that cannot judge where there is none. */
    public function first(): Findings|OutOfScope|CannotJudge
    {
        return $this->at(0);
    }

    /** @return Traversable<int, Findings|OutOfScope|CannotJudge> */
    public function getIterator(): Traversable
    {
        yield from $this->answers;
    }
}
