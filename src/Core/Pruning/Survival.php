<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Pruning;

use function array_key_exists;
use function array_replace;

use ArrayIterator;
use IteratorAggregate;

use function ksort;

use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Core\Mutant\RunnerMutatorName;
use Traversable;

/**
 * What a ledger learned of each mutator's newest judged mutants, by the
 * runner that made them and the mutator's name (ADR-0025, decisions 2 and
 * 4): the windows that decide which mutators are pruned. Losing it disables
 * pruning, never a verdict.
 *
 * @implements IteratorAggregate<string, array<string, MutatorWindow>>
 */
final readonly class Survival implements IteratorAggregate
{
    /** @param array<string, array<string, MutatorWindow>> $windows by runner, then by mutator, each sorted */
    private function __construct(private array $windows)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** This, with a mutator's window under a runner, replacing the one held. */
    public function with(Name $runner, MutatorWindow $window): self
    {
        $named = $runner->value();
        $held = array_key_exists($named, $this->windows) ? $this->windows[$named] : [];
        $windows = array_replace($held, [$window->mutator() => $window]);
        ksort($windows);
        $all = array_replace($this->windows, [$named => $windows]);
        ksort($all);

        return new self($all);
    }

    /** The window of a mutator under a runner; an empty one where nothing was learned of it. */
    public function of(Name $runner, RunnerMutatorName $mutator): MutatorWindow
    {
        $named = $runner->value();
        $name = $mutator->value();

        return array_key_exists($named, $this->windows) && array_key_exists($name, $this->windows[$named])
            ? $this->windows[$named][$name]
            : MutatorWindow::of($name);
    }

    /**
     * This, after the mutants a run judged under a runner, in the order
     * given, each mutator's window kept to the newest that fill this one.
     */
    public function after(Name $runner, Window $keep, Outcome ...$outcomes): self
    {
        $survival = $this;

        foreach ($outcomes as $outcome) {
            $held = $survival->of($runner, RunnerMutatorName::of($outcome->mutator()));
            $survival = $survival->with($runner, $held->after($outcome, $keep));
        }

        return $survival;
    }

    /**
     * The mutators of a runner whose newest mutants fill this window and let
     * nothing through, less those never pruned.
     */
    public function pruned(Name $runner, Window $window, MutatorNames $never): MutatorNames
    {
        $named = $runner->value();
        $pruned = [];

        foreach (array_key_exists($named, $this->windows) ? $this->windows[$named] : [] as $mutator => $held) {
            $kept = ! $held->isClean($window) || $never->has(RunnerMutatorName::of($mutator));
            $pruned = $kept ? $pruned : [...$pruned, $mutator];
        }

        return MutatorNames::of(...$pruned);
    }

    /**
     * This, read before another ledger's: each runner's mutators as this
     * holds them, and the other's where this holds none of that mutator.
     */
    public function and(self $other): self
    {
        $survival = $this;

        foreach ($other->windows as $runner => $windows) {
            foreach ($windows as $mutator => $window) {
                $mine = array_key_exists($runner, $this->windows)
                    && array_key_exists($mutator, $this->windows[$runner]);
                $survival = $mine ? $survival : $survival->with(Name::of($runner), $window);
            }
        }

        return $survival;
    }

    /** @return Traversable<string, array<string, MutatorWindow>> each runner's windows, by mutator */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->windows);
    }
}
