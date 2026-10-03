<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

use function array_flip;
use function array_key_exists;

use ArrayIterator;

use function count;

use Countable;

use function is_a;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\BuiltinRunner;
use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\MutatorSet;

use function sprintf;

use Traversable;

/**
 * The mutators a run makes mutants with, each constructed with no arguments
 * from the class its set registers it by (ADR-0021), less those a config
 * turns off by name. Constructing a mutator by the name a set holds is this
 * file's whole purpose.
 *
 * @internal the engine's and the runners' own
 *
 * @implements IteratorAggregate<int, Mutator>
 */
final readonly class Enabled implements Countable, IteratorAggregate
{
    private const string NOT_A_MUTATOR = 'The %s runner cannot make mutants with %s, which is not a mutator.';

    /** @param list<Mutator> $mutators */
    private function __construct(private array $mutators)
    {
    }

    /** Each of a set's mutators, but those these names, each `<set>/<Name>`, turn off. */
    public static function of(MutatorSet $set, string ...$except): self
    {
        $off = array_flip($except);
        $mutators = [];

        foreach ($set as $class) {
            $mutator = new $class();

            if (! array_key_exists($mutator->name()->value(), $off)) {
                $mutators[] = $mutator;
            }
        }

        return new self($mutators);
    }

    /** The mutators of the classes a runner's options name, or why that runner cannot make mutants with one. */
    public static function named(BuiltinRunner $runner, string ...$classes): self|CannotJudge
    {
        $mutators = [];

        foreach ($classes as $class) {
            if (! is_a($class, Mutator::class, allow_string: true)) {
                return CannotJudge::because(sprintf(self::NOT_A_MUTATOR, $runner->value, $class));
            }

            $mutators[] = $class;
        }

        return self::of(MutatorSet::of(...$mutators));
    }

    /** The gate's own engine, making mutants with these mutators. */
    public function engine(): Engine
    {
        return Engine::with(...$this->mutators);
    }

    /** @return list<class-string<Mutator>> the class of each, in order */
    public function classes(): array
    {
        $classes = [];

        foreach ($this->mutators as $mutator) {
            $classes[] = $mutator::class;
        }

        return $classes;
    }

    public function count(): int
    {
        return count($this->mutators);
    }

    /** @return Traversable<int, Mutator> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->mutators);
    }
}
