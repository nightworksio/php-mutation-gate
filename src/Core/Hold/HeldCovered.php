<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use Traversable;

/**
 * The held units whose holding tests cover them (ADR-0005, decision 10),
 * each once, by its path, with the holding tests that run any of it: the
 * tests that judge its mutants (ADR-0004, decision 5).
 *
 * @implements IteratorAggregate<int, Covered>
 */
final readonly class HeldCovered implements Countable, IteratorAggregate
{
    /** @param array<string, Covered> $covered by the held path */
    private function __construct(private array $covered)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Covered ...$covered): self
    {
        $collected = [];

        foreach ($covered as $one) {
            $collected += [$one->unit()->path()->value() => $one];
        }

        return new self($collected);
    }

    public function with(Covered $covered): self
    {
        return self::of(...$this, ...[$covered]);
    }

    /** The holding tests that run the unit held at this path; none for a unit not among these. */
    public function testsOf(Path $unit): TestIds
    {
        return array_key_exists($unit->value(), $this->covered)
            ? $this->covered[$unit->value()]->tests()
            : TestIds::none();
    }

    /**
     * Those of these tests, covering a mutant in this file, that judge it:
     * the ones among the holding tests of the held unit the file is in, where
     * it is in one; every one, where it is not.
     */
    public function judgingAmong(Path $file, TestIds $covering): TestIds
    {
        foreach ($this->covered as $covered) {
            if ($file->within($covered->unit()->path())) {
                return $covering->among($covered->tests());
            }
        }

        return $covering;
    }

    public function count(): int
    {
        return count($this->covered);
    }

    /** @return Traversable<int, Covered> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->covered));
    }
}
