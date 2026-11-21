<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_filter;
use function array_key_exists;
use function array_values;

use ArrayIterator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use Traversable;

/**
 * The files a run made mutants on some lines of alone (ADR-0193): a part of
 * a cut run's tests makes the mutants that touch a line its tests cover or
 * one no test covers, and no other. A file it lists none of it made whole.
 *
 * @implements IteratorAggregate<int, MadeFile>
 */
final readonly class MadeLines implements IteratorAggregate
{
    /** @param array<string, MadeFile> $files by each file's path */
    private function __construct(private array $files)
    {
    }

    /** Every file made whole. */
    public static function whole(): self
    {
        return new self([]);
    }

    /** These lines, with a file made on some lines alone, in place of what they said of it. */
    public function with(MadeFile $file): self
    {
        return new self([...$this->files, $file->path()->value() => $file]);
    }

    /** These lines, and another run's after them, the later's of a file both list. */
    public function and(self $later): self
    {
        return new self([...$this->files, ...$later->files]);
    }

    /** Whether the run made every file whole. */
    public function isWhole(): bool
    {
        return $this->files === [];
    }

    /** Whether the run made the mutants spanning these lines of a file: touching a line it made, or in a whole file. */
    public function makes(Path $file, Line $first, Line $last): bool
    {
        return ! array_key_exists($file->value(), $this->files) || $this->files[$file->value()]->makes($first, $last);
    }

    /**
     * The files it made on some lines alone that are inside a unit: the
     * unit's own file, or each file inside its held path, in their order.
     *
     * @return list<MadeFile>
     */
    public function inside(Path $unit): array
    {
        return array_key_exists($unit->value(), $this->files)
            ? [$this->files[$unit->value()]]
            : array_values(array_filter($this->files, static fn(MadeFile $file): bool => $file->path()->within($unit)));
    }

    /** @return Traversable<int, MadeFile> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->files));
    }
}
