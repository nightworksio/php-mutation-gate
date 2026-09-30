<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use function array_filter;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use Traversable;

use function usort;

/**
 * What every check found, the findings that fail a run first, then those
 * that slow it, then advice, each kind in the order the checks ran.
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

    /** These findings and the others', ordered by how much each matters. */
    public function and(self ...$others): self
    {
        $findings = $this->findings;

        foreach ($others as $other) {
            $findings = [...$findings, ...$other->findings];
        }

        usort($findings, static fn(Finding $one, Finding $other): int => self::rank($one) <=> self::rank($other));

        return new self($findings);
    }

    /** The findings of one severity. */
    public function thatAre(Severity $severity): self
    {
        return new self(array_values(array_filter(
            $this->findings,
            static fn(Finding $finding): bool => $finding->severity() === $severity,
        )));
    }

    /** Whether a run would exit 2 or be cannot judge. */
    public function failARun(): bool
    {
        return $this->thatAre(Severity::WillFail)->count() > 0;
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

    private static function rank(Finding $finding): int
    {
        return match ($finding->severity()) {
            Severity::WillFail => 0,
            Severity::Slow => 1,
            Severity::Advice => 2,
        };
    }
}
