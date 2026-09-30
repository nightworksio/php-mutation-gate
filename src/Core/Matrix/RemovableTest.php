<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Matrix;

use function array_values;

use ArrayIterator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestName;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use Traversable;

/**
 * A whole test outside the kept set, what it costs, and for each mutant it
 * kills, the kept test that kills it too (ADR-0014, decision 8).
 *
 * @implements IteratorAggregate<int, KeptKill>
 */
final readonly class RemovableTest implements IteratorAggregate
{
    /** @param list<KeptKill> $kills */
    private function __construct(
        private TestName|TestId $test,
        private Seconds|Unmeasured $seconds,
        private array $kills,
    ) {
    }

    public static function of(TestName|TestId $test, Seconds|Unmeasured $seconds, KeptKill ...$kills): self
    {
        return new self($test, $seconds, array_values($kills));
    }

    public function test(): TestName|TestId
    {
        return $this->test;
    }

    /** What the test costs: the time its rows ran for under coverage, where a coverage run timed one. */
    public function seconds(): Seconds|Unmeasured
    {
        return $this->seconds;
    }

    /** @return Traversable<int, KeptKill> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->kills);
    }
}
