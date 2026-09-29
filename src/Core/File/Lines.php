<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\File;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;

use function ksort;

use Traversable;

/**
 * Lines of one file, each once, in ascending order.
 *
 * @implements IteratorAggregate<int, Line>
 */
final readonly class Lines implements Countable, IteratorAggregate
{
    /** @param array<int, Line> $lines by number, ascending */
    private function __construct(private array $lines)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Line ...$lines): self
    {
        $collected = self::none();

        foreach ($lines as $line) {
            $collected = $collected->with($line);
        }

        return $collected;
    }

    public function with(Line $line): self
    {
        $lines = $this->lines;
        $lines[$line->number()] = $line;
        ksort($lines);

        return new self($lines);
    }

    public function has(Line $line): bool
    {
        return array_key_exists($line->number(), $this->lines);
    }

    public function count(): int
    {
        return count($this->lines);
    }

    /** @return Traversable<int, Line> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->lines));
    }
}
