<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

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

    /** @return Traversable<int, Findings|OutOfScope|CannotJudge> */
    public function getIterator(): Traversable
    {
        yield from $this->answers;
    }
}
