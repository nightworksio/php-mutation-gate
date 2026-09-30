<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use IteratorAggregate;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;

use Traversable;

/**
 * Numbers by name, in the order they were written: seconds per line by path
 * prefix, the lowest score of each badge colour.
 *
 * @implements IteratorAggregate<string, int|float>
 */
final readonly class Table implements IteratorAggregate
{
    /** @param array<array-key, int|float> $numbers by name, which PHP keys as a number where it reads as one */
    private function __construct(private array $numbers)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    /** One number by its name: `Table::row('src/Legacy', 2)`. */
    public static function row(string $name, int|float $number): self
    {
        return new self([$name => $number]);
    }

    /** This table with a later layer's entries laid over its own, by key. */
    public function merged(self $later): self
    {
        $numbers = $this->numbers;

        foreach ($later->numbers as $key => $number) {
            $numbers[$key] = $number;
        }

        return new self($numbers);
    }

    /** The table as a config writes it: an object of numbers, each as it was written. */
    public function written(): Json
    {
        $written = Json::object();

        foreach ($this->numbers as $key => $number) {
            $written = $written->with(Member::of(sprintf('%s', $key), $number));
        }

        return $written;
    }

    /** @return Traversable<string, int|float> */
    public function getIterator(): Traversable
    {
        foreach ($this->numbers as $key => $number) {
            yield sprintf('%s', $key) => $number;
        }
    }
}
