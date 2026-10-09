<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Pruning;

use function max;
use function mb_strlen;
use function mb_substr;
use function mb_substr_count;

use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;
use function sprintf;

/**
 * What one mutator's newest judged mutants came to under one runner
 * (ADR-0025, decision 2), oldest first, as a `0` for each mutant killed and
 * a `1` for each let through; and the last mutant let through, where one
 * ever was. It keeps no more than the window it was last given.
 */
final readonly class MutatorWindow
{
    private const string THROUGH = '1';

    private const string KILLED = '0';

    private const string SHAPE = '/^[01]*$/D';

    private function __construct(private string $mutator, private string $outcomes, private string|NotGiven $last)
    {
    }

    /** A mutator nothing was learned of yet. */
    public static function of(string $mutator): self
    {
        return new self($mutator, '', NotGiven::value());
    }

    /** A mutator's window as a ledger wrote it; none where its outcomes are not so written. */
    public static function written(string $mutator, string $outcomes, string|NotGiven $last): self|NotGiven
    {
        return preg_match(self::SHAPE, $outcomes) === 1 ? new self($mutator, $outcomes, $last) : NotGiven::value();
    }

    /** This window after one more judged mutant of its mutator, kept to the newest that fill this one. */
    public function after(Outcome $outcome, Window $keep): self
    {
        $all = sprintf('%s%s', $this->outcomes, $outcome->letThrough() ? self::THROUGH : self::KILLED);

        return new self(
            $this->mutator,
            mb_substr($all, max(0, mb_strlen($all) - $keep->mutants())),
            $outcome->letThrough() ? $outcome->mutant() : $this->last,
        );
    }

    /** Whether its newest mutants fill this window and none of them let the change through. */
    public function isClean(Window $window): bool
    {
        $size = $window->mutants();

        return $size > 0
            && mb_strlen($this->outcomes) >= $size
            && mb_substr_count(mb_substr($this->outcomes, -$size), self::THROUGH) === 0;
    }

    public function mutator(): string
    {
        return $this->mutator;
    }

    /** Its outcomes, oldest first, as a ledger writes them. */
    public function outcomes(): string
    {
        return $this->outcomes;
    }

    /** The id of the last mutant let through; none where none ever was. */
    public function last(): string|NotGiven
    {
        return $this->last;
    }
}
