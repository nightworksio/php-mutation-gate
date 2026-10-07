<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_values;

use ArrayIterator;

use function in_array;

use IteratorAggregate;

use function min;

use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use Traversable;

/**
 * The mutators whose mutants hang most, whose silence limit has a lower
 * floor than `timeouts.seconds` (ADR-0008, decision 2): `timeouts.tighter`.
 * Each is named by its short name, the last part of the name a runner gives
 * it, after its last `\` or `/`, so one name covers Pest's class, the
 * default set's `default/<Name>` and Infection's own.
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class TighterSilence implements IteratorAggregate
{
    /**
     * The mutators the gate's own runs found hang most, by the names Pest and
     * the default set give them and the names Infection gives the same
     * changes.
     */
    public const array MUTATORS = [
        'RemoveArrayItem',
        'DecrementInteger',
        'IncrementInteger',
        'ForeachEmptyIterable',
        'UnwrapArrayValues',
        'InstanceOfToTrue',
        'InstanceOfToFalse',
        'TernaryNegated',
        'ArrayItemRemoval',
        'Foreach_',
        'InstanceOf_',
        'Ternary',
    ];

    /** The silence limit's floor for those mutators, in seconds. */
    public const int FLOOR = 7;

    /** @param list<string> $mutators */
    private function __construct(private array $mutators, private Seconds $floor)
    {
    }

    /** The mutators the gate's own runs found hang most, with a floor of seven seconds. */
    public static function standard(): self
    {
        return new self(self::MUTATORS, Seconds::of(self::FLOOR));
    }

    /** No mutator's silence limit has a lower floor. */
    public static function none(): self
    {
        return new self([], Seconds::of(self::FLOOR));
    }

    /** These mutators, by their short names, with this floor. */
    public static function of(Seconds $floor, string ...$mutators): self
    {
        return new self(array_values($mutators), $floor);
    }

    /**
     * The floor of the silence limit of a mutant of this mutator, as a runner
     * names it: the lower of the two where it is listed.
     */
    public function floorOf(RunnerMutatorName $mutator, Seconds $floor): Seconds
    {
        return in_array($mutator->short(), $this->mutators, strict: true)
            ? Seconds::of(min($floor->seconds(), $this->floor->seconds()))
            : $floor;
    }

    public function floor(): Seconds
    {
        return $this->floor;
    }

    /** @return Traversable<int, string> each mutator, by its short name */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->mutators);
    }
}
