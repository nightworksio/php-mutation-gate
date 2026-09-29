<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Registry;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function sprintf;

/**
 * What extensions registered for one kind of thing, by name, each with the
 * package that registered it. A package that registers a name again replaces
 * its own entry; two packages registering one name is a conflict.
 *
 * @template-covariant T
 */
final readonly class Entries
{
    /**
     * @param string                                         $kind    what is registered, as a message names it
     * @param array<string, array{origin: string, entry: T}> $entries by name
     */
    public function __construct(private string $kind, private array $entries = []) {}

    /**
     * @template U
     *
     * @param  U                $entry
     * @return self<T|U>
     */
    public function with(string $name, string $origin, mixed $entry): self
    {
        $entries = $this->entries;
        $entries[$name] = ['origin' => $origin, 'entry' => $entry];

        return new self($this->kind, $entries);
    }

    /** @return T|CannotJudge */
    public function find(string $name): mixed
    {
        return array_key_exists($name, $this->entries)
            ? $this->entries[$name]['entry']
            : CannotJudge::because(sprintf('No %s is registered as "%s".', $this->kind, $name));
    }

    /**
     * The names both register, each said as the sentence the user reads.
     *
     * @param  self<mixed>  $other
     * @return list<string>
     */
    public function conflictsWith(self $other): array
    {
        $conflicts = [];

        foreach ($other->entries as $name => $theirs) {
            $ours = array_key_exists($name, $this->entries) ? $this->entries[$name]['origin'] : $theirs['origin'];

            if ($ours !== $theirs['origin']) {
                $conflicts[] = sprintf('Two packages register a %s named "%s": %s and %s.', $this->kind, $name, $ours, $theirs['origin']);
            }
        }

        return $conflicts;
    }

    /**
     * These entries and another's; where both name the same thing, the other's.
     *
     * @template U
     *
     * @param  self<U>   $other
     * @return self<T|U>
     */
    public function merge(self $other): self
    {
        return new self($this->kind, [...$this->entries, ...$other->entries]);
    }
}
