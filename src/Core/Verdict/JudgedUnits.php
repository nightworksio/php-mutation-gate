<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\File\Path;

use function sprintf;

use Traversable;

/**
 * Judged units, in the order they were added.
 *
 * @implements IteratorAggregate<int, JudgedUnit>
 */
final readonly class JudgedUnits implements Countable, IteratorAggregate
{
    private const string NONE = 'No unit of the run holds %s.';

    /** @param list<JudgedUnit> $units */
    private function __construct(private array $units)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(JudgedUnit ...$units): self
    {
        return new self(array_values($units));
    }

    public function with(JudgedUnit $unit): self
    {
        return new self([...$this->units, $unit]);
    }

    /** These units, then those. */
    public function and(self $those): self
    {
        return new self([...$this->units, ...$those->units]);
    }

    /** The first of these units that holds a file: the file's own, or the held path it is in; or that none does. */
    public function holding(Path $file): JudgedUnit|CannotTell
    {
        foreach ($this->units as $unit) {
            if ($file->within($unit->unit()->path())) {
                return $unit;
            }
        }

        return CannotTell::because(sprintf(self::NONE, $file->value()));
    }

    public function count(): int
    {
        return count($this->units);
    }

    /** @return Traversable<int, JudgedUnit> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->units);
    }
}
