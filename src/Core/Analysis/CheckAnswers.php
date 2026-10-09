<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_key_exists;
use function array_pad;
use function array_values;
use function count;

use Countable;

use function iterator_count;

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

    /**
     * These answers, one in the place of each of these checks: one that
     * cannot judge in the place of each check the analyser gave no answer to.
     */
    public function padded(MutantChecks $checks): self
    {
        return new self(array_pad($this->answers, iterator_count($checks), CannotJudge::because(self::UNANSWERED)));
    }

    /** The answer to the first check, which a batch of one check holds; one that cannot judge where there is none. */
    public function first(): Findings|OutOfScope|CannotJudge
    {
        return array_key_exists(0, $this->answers) ? $this->answers[0] : CannotJudge::because(self::UNANSWERED);
    }

    /** @return Traversable<int, Findings|OutOfScope|CannotJudge> */
    public function getIterator(): Traversable
    {
        yield from $this->answers;
    }
}
