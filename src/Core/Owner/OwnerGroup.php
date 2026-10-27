<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Owner;

use function array_map;
use function array_values;

use ArrayIterator;

use function count;

use Countable;

use function implode;

use IteratorAggregate;
use Traversable;

/**
 * The exact owner list of the last CODEOWNERS rule matching a file, which a
 * survivor of it belongs to, in the rule's order; unowned where no rule
 * matches the file, or its rule names no owner (ADR-0022).
 *
 * @implements IteratorAggregate<int, Owner>
 */
final readonly class OwnerGroup implements Countable, IteratorAggregate
{
    /** What a report calls the group of files no one owns. */
    public const string UNOWNED = 'unowned';

    /** @param list<Owner> $owners */
    private function __construct(private array $owners)
    {
    }

    public static function of(Owner ...$owners): self
    {
        return new self(array_values($owners));
    }

    public static function unowned(): self
    {
        return new self([]);
    }

    public function isUnowned(): bool
    {
        return $this->owners === [];
    }

    /** The group as a report heads it: its owners in the rule's order, `@acme/payments @jo`; or `unowned`. */
    public function name(): string
    {
        return $this->isUnowned()
            ? self::UNOWNED
            : implode(' ', array_map(static fn(Owner $owner): string => $owner->value(), $this->owners));
    }

    public function count(): int
    {
        return count($this->owners);
    }

    /** @return Traversable<int, Owner> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->owners);
    }
}
