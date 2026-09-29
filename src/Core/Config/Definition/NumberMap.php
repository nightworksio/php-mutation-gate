<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config\Definition;

use function is_float;

use NightWorksIO\MutationGate\Core\Config\Table;

use function sprintf;

/** An object whose keys are data, such as path prefixes or colours, and whose values are numbers. */
final readonly class NumberMap implements Node
{
    private function __construct(private Number $number)
    {
    }

    public static function of(Number $number): self
    {
        return new self($number);
    }

    public function read(mixed $value, string $at): Reading
    {
        if (! Json::isMap($value)) {
            return Reading::mismatch($at, $this->expected(), $value);
        }

        $numbers = [];
        $problems = [];

        foreach ($value as $key => $number) {
            $name = sprintf('%s', $key);
            $reading = $this->number->read($number, At::entry($at, $name));
            $read = $reading->value();

            if (is_float($read)) {
                $numbers[$name] = $read;
            }

            $problems = [...$problems, ...$reading->problems()];
        }

        return $problems === []
            ? Reading::of(Table::of($numbers), Json::object($value))
            : Reading::refused($problems);
    }

    public function expected(): string
    {
        return 'an object of numbers';
    }

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => $this->number->schema()];
    }

    public function effects(): array
    {
        return [];
    }
}
