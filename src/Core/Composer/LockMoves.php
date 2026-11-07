<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

use function array_diff_assoc;
use function array_key_exists;
use function array_keys;
use function in_array;
use function ksort;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Format\Lenient;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sort;

use const SORT_STRING;

use function sprintf;

/**
 * What a change to a `composer.lock` moved: each package, by name, whose
 * entry under `packages` or `packages-dev` was added, removed or changed in
 * any field, and whether any other key moved besides `content-hash` and
 * `_readme`, which only follow from the rest (ADR-0030).
 *
 * @internal
 */
final readonly class LockMoves
{
    /** The lists a lock pins its packages in, each entry named by its `name`. */
    private const array LISTS = ['packages', 'packages-dev'];

    /** The keys a lock writes from the rest of it, which move nothing of their own. */
    private const array DERIVED = ['content-hash', '_readme'];

    private const string UNREADABLE = 'A composer.lock, at the base or now, is not a lock Composer writes.';

    /** What a lock says of a package that changes what every test may load, not only what the package holds. */
    private const array WIDE = ['type', GateEntry::EXTRA];

    /** @param list<string> $packages */
    private function __construct(private array $packages, private bool $beyond, private bool $wide)
    {
    }

    /** What moved from the lock at the base to the lock now, or why either cannot be read as a lock. */
    public static function between(Contents $before, Contents $now): self|CannotJudge
    {
        try {
            [$then, $thenRest] = self::read($before);
            [$after, $afterRest] = self::read($now);
        } catch (NotInShape) {
            return CannotJudge::because(self::UNREADABLE);
        }

        $moved = array_keys(array_diff_assoc($then, $after) + array_diff_assoc($after, $then));
        sort($moved, SORT_STRING);

        return new self($moved, $thenRest !== $afterRest, self::isWideMove($moved, $then, $after));
    }

    /** @return list<string> each package whose pin moved, by name, in byte order */
    public function packages(): array
    {
        return $this->packages;
    }

    /** Whether a key besides the package lists and those that follow from the rest moved, such as `platform`. */
    public function beyondPackages(): bool
    {
        return $this->beyond;
    }

    /**
     * Whether a package was added or removed, or moved its `autoload.files`, its `extra` or its `type`, which reach
     * every test whatever each loaded (ADR-0113).
     */
    public function isWide(): bool
    {
        return $this->wide;
    }

    /**
     * @param list<string>          $moved
     * @param array<string, string> $then
     * @param array<string, string> $after
     *
     * @throws NotInShape
     */
    private static function isWideMove(array $moved, array $then, array $after): bool
    {
        foreach ($moved as $name) {
            $was = array_key_exists($name, $then) ? self::shapeOf($then[$name]) : null;
            $is = array_key_exists($name, $after) ? self::shapeOf($after[$name]) : null;

            if ($was === null || $was !== $is) {
                return true;
            }
        }

        return false;
    }

    /**
     * What of an entry reaches every test, as JSON, by the key.
     *
     * @return array<string, string>
     *
     * @throws NotInShape
     */
    private static function shapeOf(string $entry): array
    {
        $read = Node::decode($entry);
        $shape = ['files' => Lenient::json($read->field('autoload')->field('files'))];

        foreach (self::WIDE as $key) {
            $shape[$key] = Lenient::json($read->field($key));
        }

        return $shape;
    }

    /**
     * Each package's entry as JSON, by its name, and every other key's value
     * as JSON, by the key.
     *
     * @return array{array<string, string>, array<string, string>}
     *
     * @throws NotInShape
     */
    private static function read(Contents $lock): array
    {
        $packages = [];
        $rest = [];

        foreach (Node::decode($lock->text())->entries() as $key => $value) {
            $key = sprintf('%s', $key);

            if (in_array($key, self::LISTS, strict: true)) {
                $packages += self::pinned($value);

                continue;
            }

            if (! in_array($key, self::DERIVED, strict: true)) {
                $rest[$key] = $value->json();
            }
        }

        ksort($rest, SORT_STRING);

        return [$packages, $rest];
    }

    /**
     * @return array<string, string> each entry of a package list as JSON, by its name
     *
     * @throws NotInShape
     */
    private static function pinned(Node $list): array
    {
        $pinned = [];

        foreach ($list->items() as $entry) {
            $pinned[$entry->field('name')->text()] = $entry->json();
        }

        return $pinned;
    }
}
