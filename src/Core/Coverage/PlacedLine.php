<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Coverage;

use function array_flip;
use function array_keys;

use ArrayIterator;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\File\Path;
use Traversable;

/**
 * One line of a file as a coverage map's file lists it: the places of the
 * tests that ran it in the map's list of tests, each once, in the order they
 * ran it; none for a line the run missed.
 *
 * @implements IteratorAggregate<int, int<0, max>>
 */
final readonly class PlacedLine implements IteratorAggregate
{
    /** @param list<int<0, max>> $places each once */
    private function __construct(private Path $file, private int $line, private array $places)
    {
    }

    /** @param int<0, max> ...$places a place named twice is held once */
    public static function of(Path $file, int $line, int ...$places): self
    {
        return new self($file, $line, array_keys(array_flip($places)));
    }

    public function file(): Path
    {
        return $this->file;
    }

    public function line(): int
    {
        return $this->line;
    }

    /** This line, with the tests at these places after those it holds, each once. */
    public function and(self $more): self
    {
        return self::of($this->file, $this->line, ...$this->places, ...$more->places);
    }

    /** Whether no test ran it. */
    public function isMissed(): bool
    {
        return $this->places === [];
    }

    /** @return Traversable<int, int<0, max>> the places of the tests that ran the line, none where none did */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->places);
    }
}
