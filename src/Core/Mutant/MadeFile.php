<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function array_any;
use function array_values;

use ArrayIterator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use Traversable;

/**
 * A file a run made mutants on some lines of alone (ADR-0193): its path,
 * its last line, and the spans of lines it made the mutants of.
 *
 * @implements IteratorAggregate<int, LineSpan>
 */
final readonly class MadeFile implements IteratorAggregate
{
    /** @param list<LineSpan> $spans */
    private function __construct(private Path $path, private Line $last, private array $spans)
    {
    }

    public static function of(Path $path, Line $last, LineSpan ...$spans): self
    {
        return new self($path, $last, array_values($spans));
    }

    public function path(): Path
    {
        return $this->path;
    }

    public function last(): Line
    {
        return $this->last;
    }

    /** Whether the run made the mutants spanning these lines: those touching a line it made. */
    public function makes(Line $first, Line $last): bool
    {
        return array_any($this->spans, fn(LineSpan $span): bool => $span->touches($first, $last));
    }

    /** @return Traversable<int, LineSpan> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->spans);
    }
}
