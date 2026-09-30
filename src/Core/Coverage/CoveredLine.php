<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_values;

use ArrayIterator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Path;
use Traversable;

/**
 * One line of a file and the ids of the tests that ran it, as a coverage map
 * is read: many of these build a map at once.
 *
 * @implements IteratorAggregate<int, string>
 */
final readonly class CoveredLine implements IteratorAggregate
{
    /** @param list<string> $tests */
    private function __construct(private Path $file, private int $line, private array $tests)
    {
    }

    public static function of(Path $file, int $line, string ...$tests): self
    {
        return new self($file, $line, array_values($tests));
    }

    public function file(): Path
    {
        return $this->file;
    }

    public function line(): int
    {
        return $this->line;
    }

    /** @return Traversable<int, string> the ids of the tests that ran the line */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->tests);
    }
}
