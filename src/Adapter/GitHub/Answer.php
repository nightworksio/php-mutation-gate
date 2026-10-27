<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use function array_filter;
use function array_map;
use function array_values;

use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;

/** What GitHub's API answered, read one field at a time; a field it did not send reads as empty. */
final readonly class Answer
{
    private function __construct(private Node $node)
    {
    }

    /** @param array<mixed> $data */
    public static function of(array $data): self
    {
        return new self(Node::decode(JsonText::compact($data)));
    }

    /** The text at a path of keys, or nothing where there is none. */
    public function text(string ...$keys): string
    {
        return Lenient::text($this->at($keys));
    }

    /** Whether the value at a path of keys is true; false where it is anything else, or there is none. */
    public function isTrue(string ...$keys): bool
    {
        return Lenient::boolean($this->at($keys), otherwise: false);
    }

    /** Whether the value at a path of keys is false; not where it is anything else, or there is none. */
    public function isFalse(string ...$keys): bool
    {
        return ! Lenient::boolean($this->at($keys), otherwise: true);
    }

    /** The number at a path of keys, or 0 where there is none. */
    public function number(string ...$keys): int
    {
        return Lenient::integer($this->at($keys));
    }

    /**
     * The objects in the list at a path of keys, or in the answer itself.
     *
     * @return list<self>
     */
    public function items(string ...$keys): array
    {
        return array_map(
            static fn(Node $item): self => new self($item),
            array_values(array_filter(Lenient::items($this->at($keys)), Lenient::holdsMembers(...))),
        );
    }

    /**
     * The texts in the list at a path of keys, each item that is not one left out.
     *
     * @return list<string>
     */
    public function texts(string ...$keys): array
    {
        $texts = [];

        foreach (Lenient::items($this->at($keys)) as $item) {
            $text = Lenient::text($item);
            $texts = $text === '' ? $texts : [...$texts, $text];
        }

        return $texts;
    }

    /** @param array<string> $keys */
    private function at(array $keys): Node
    {
        $node = $this->node;

        foreach ($keys as $key) {
            $node = $node->field($key);
        }

        return $node;
    }
}
