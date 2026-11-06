<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function explode;

use Generator;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Test\TestMethod;

use function sprintf;

/**
 * The tests a ledger's records point into by index, as its `tests` section
 * holds them: each test's id under its class, the part before its first
 * `::`, as `{class: [rest, …]}`, the classes in the order they are first
 * named. An id with no class before a `::` sits whole under `""`. Each test's
 * index is its place in that order. A ledger of an earlier format holds a
 * plain list of ids, which reads as it is.
 *
 * @internal the shape of the `tests` section of the ledger file
 */
final readonly class TestsTable
{
    /**
     * @param array<array-key, list<string>> $classes each class's tests, by the class, which PHP keys as a number
     *                                                where it reads as one
     */
    private function __construct(private array $classes)
    {
    }

    /** These test ids, each once, under their classes. */
    public static function of(string ...$ids): self
    {
        $classes = [];

        foreach ($ids as $id) {
            $parts = explode(TestMethod::SEPARATOR, $id, 2);
            $classed = count($parts) === 2 && $parts[0] !== '';
            $class = $classed ? $parts[0] : '';
            $rest = $classed ? $parts[1] : $id;
            $classes[$class][$rest] = $rest;
        }

        return new self(array_map(array_values(...), $classes));
    }

    /**
     * The test ids a ledger's `tests` section holds, each at its index; none where any is not text, since an
     * index past one would then point at the wrong test.
     *
     * @return list<string>
     */
    public static function read(Node $tests): array
    {
        try {
            return $tests->kind() === Kind::Map ? self::grouped($tests) : self::listed($tests);
        } catch (NotInShape) {
            return [];
        }
    }

    /** @return array<string, int> each test's index, by its id */
    public function indices(): array
    {
        $indices = [];

        foreach ($this->ids() as $index => $id) {
            $indices[$id] = $index;
        }

        return $indices;
    }

    /** @return Generator<string, string> each class's tests, as JSON text, by the class */
    public function written(): Generator
    {
        foreach ($this->classes as $class => $tests) {
            yield sprintf('%s', $class) => JsonText::compact($tests);
        }
    }

    /** @return list<string> */
    private function ids(): array
    {
        $ids = [];

        foreach ($this->classes as $class => $tests) {
            $ids[] = array_map(static fn(string $rest): string => self::joined(sprintf('%s', $class), $rest), $tests);
        }

        return array_merge(...$ids);
    }

    private static function joined(string $class, string $rest): string
    {
        return $class === '' ? $rest : TestMethod::id($class, $rest)->value();
    }

    /**
     * @return list<string>
     *
     * @throws NotInShape
     */
    private static function grouped(Node $tests): array
    {
        $ids = [];
        $entries = $tests->entries();

        foreach (array_keys($entries) as $class) {
            $ids[] = array_map(
                static fn(Node $rest): string => self::joined(sprintf('%s', $class), $rest->text()),
                $entries[$class]->items(),
            );
        }

        return array_merge(...$ids);
    }

    /**
     * @return list<string>
     *
     * @throws NotInShape
     */
    private static function listed(Node $tests): array
    {
        return array_map(static fn(Node $id): string => $id->text(), $tests->items());
    }
}
