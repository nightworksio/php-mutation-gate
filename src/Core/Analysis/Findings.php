<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Analysis;

use function array_any;
use function array_filter;
use function array_map;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;

use function sort;
use function sprintf;

use Traversable;

/**
 * What a static analyser reports about some files, in the order it reported
 * it (ADR-0020, decision 9).
 *
 * @implements IteratorAggregate<int, Finding>
 */
final readonly class Findings implements Countable, IteratorAggregate
{
    /** @param list<Finding> $findings */
    private function __construct(private array $findings)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Finding ...$findings): self
    {
        return new self(array_values($findings));
    }

    /**
     * Whether these, a mutant's, reject it: any error among them that the
     * original files' findings do not hold, by its code and message. A
     * project with a baseline, or with known errors, is judged by what the
     * mutant adds, so its analyser need not run clean.
     */
    public function rejects(self $original): bool
    {
        return count($this->newErrors($original)) > 0;
    }

    /** The errors among these, a mutant's, that the original files' findings do not hold, by code and message. */
    public function newErrors(self $original): self
    {
        return new self(array_values(array_filter(
            $this->findings,
            static fn(Finding $finding): bool => $finding->isError() && ! $original->holds($finding),
        )));
    }

    /**
     * Whether these are the same findings as those, each as many times, by
     * code and message and whatever their order: as a file printed another
     * way must analyse to stand in for the file itself.
     */
    public function same(self $other): bool
    {
        return $this->keys() === $other->keys();
    }

    public function count(): int
    {
        return count($this->findings);
    }

    /** @return Traversable<int, Finding> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->findings);
    }

    private function holds(Finding $sought): bool
    {
        return array_any($this->findings, static fn(Finding $finding): bool => $finding->equals($sought));
    }

    /** @return list<string> each finding's code and message, sorted */
    private function keys(): array
    {
        $keys = array_map(
            static fn(Finding $finding): string => sprintf("%s\n%s", $finding->code(), $finding->message()),
            $this->findings,
        );
        sort($keys);

        return $keys;
    }
}
