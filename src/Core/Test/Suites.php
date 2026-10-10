<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function array_map;
use function array_values;

use ArrayIterator;

use function implode;

use IteratorAggregate;
use Traversable;

/**
 * The `<testsuite>`s of the PHPUnit config whose tests a run runs: every
 * suite, or these by name, as `tests.suites` lists them (ADR-0002) or
 * `--suite` names one (ADR-0025, decision 9).
 *
 * @implements IteratorAggregate<int, SuiteName>
 */
final readonly class Suites implements IteratorAggregate
{
    /** @param list<SuiteName> $names */
    private function __construct(private array $names)
    {
    }

    /** Every suite the PHPUnit config declares, as a run with no suite named runs them. */
    public static function all(): self
    {
        return new self([]);
    }

    /** These suites alone. */
    public static function named(SuiteName $first, SuiteName ...$more): self
    {
        return new self([$first, ...array_values($more)]);
    }

    /** Whether it runs every suite, naming none. */
    public function isAll(): bool
    {
        return $this->names === [];
    }

    /** The names, in the order given, as PHPUnit's `--testsuite` takes them: comma-separated. */
    public function joined(): string
    {
        return implode(',', array_map(static fn(SuiteName $name): string => $name->value(), $this->names));
    }

    /** @return Traversable<int, SuiteName> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->names);
    }
}
