<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_map;

use IteratorAggregate;

use function range;

use Traversable;

/**
 * The places a runner runs its processes side by side in: one for each
 * process it may run at once, numbered from one, or the one place that tells
 * its process nothing where it runs one at a time.
 *
 * @implements IteratorAggregate<int, WorkerSlot>
 */
final readonly class WorkerSlots implements IteratorAggregate
{
    /** @param non-empty-list<WorkerSlot> $slots */
    private function __construct(private array $slots)
    {
    }

    /** The places for this many processes, in a run that names itself by a token of its own. */
    public static function of(ProcessCount $processes, string $run): self
    {
        $count = $processes->count();

        return $count === 1
            ? self::alone()
            : new self(array_map(
                static fn(int $number): WorkerSlot => WorkerSlot::of($number, $run),
                range(1, $count),
            ));
    }

    /** The one place of a runner that runs one process at a time. */
    public static function alone(): self
    {
        return new self([WorkerSlot::alone()]);
    }

    /** @return Traversable<int, WorkerSlot> */
    public function getIterator(): Traversable
    {
        yield from $this->slots;
    }
}
