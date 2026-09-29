<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_key_exists;
use function is_array;
use function is_int;
use function is_string;

/** What GitHub's API answered, read one field at a time; a field it did not send reads as empty. */
final readonly class Answer
{
    /** @param array<mixed> $data */
    private function __construct(private array $data)
    {
    }

    /** @param array<mixed> $data */
    public static function of(array $data): self
    {
        return new self($data);
    }

    /** The text at a path of keys, or nothing where there is none. */
    public function text(string ...$keys): string
    {
        $value = $this->at($keys);

        return is_string($value) ? $value : '';
    }

    /** The number at a path of keys, or 0 where there is none. */
    public function number(string ...$keys): int
    {
        $value = $this->at($keys);

        return is_int($value) ? $value : 0;
    }

    /**
     * The objects in the list at a path of keys, or in the answer itself.
     *
     * @return list<self>
     */
    public function items(string ...$keys): array
    {
        $value = $this->at($keys);
        $items = [];

        foreach (is_array($value) ? $value : [] as $item) {
            $items = is_array($item) ? [...$items, new self($item)] : $items;
        }

        return $items;
    }

    /** @param list<string> $keys */
    private function at(array $keys): mixed
    {
        $value = $this->data;

        foreach ($keys as $key) {
            $value = is_array($value) && array_key_exists($key, $value) ? $value[$key] : [];
        }

        return $value;
    }
}
