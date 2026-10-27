<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_filter;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Owner\CodeOwners;
use NightWorksIO\MutationGate\Core\Owner\OwnerFloors;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use Traversable;

/**
 * Every owner `owners.floors` holds to a floor, each judged over the
 * mutants of every file whose CODEOWNERS rule names it, in the order of
 * their names (ADR-0022). An owner can be in several groups, so these
 * overlap where the owner groups do not. None where no owner has a floor.
 *
 * @implements IteratorAggregate<int, OwnerFloorVerdict>
 */
final readonly class OwnerFloorVerdicts implements Countable, IteratorAggregate
{
    /** @param list<OwnerFloorVerdict> $verdicts */
    private function __construct(private array $verdicts)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** Each owner with a floor, over these trees' mutants of the files the rules name it for. */
    public static function of(TreeVerdicts $trees, CodeOwners $owners, Uncovered $uncovered, OwnerFloors $floors): self
    {
        $mutants = $trees->mutants();
        $files = [];

        foreach ($mutants as $judged) {
            $file = $judged->mutant()->location()->file();
            $files[$file->value()] = $file;
        }

        $verdicts = [];

        foreach ($floors as $floor) {
            $owned = Paths::of(...array_values(array_filter(
                $files,
                static fn(Path $file): bool => $owners->groupOf($file)->names($floor->owner()),
            )));
            $verdicts[] = OwnerFloorVerdict::judged(
                $floor->owner(),
                $floor->floor(),
                $mutants->inFiles($owned),
                $uncovered,
            );
        }

        return new self($verdicts);
    }

    public function count(): int
    {
        return count($this->verdicts);
    }

    /** @return Traversable<int, OwnerFloorVerdict> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->verdicts);
    }
}
