<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Registry;

use function array_key_exists;
use function array_keys;
use function array_map;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Definition\Nearest;

use function sprintf;

/**
 * What extensions registered for one kind of thing, by name, each with the
 * package that registered it. A package that registers a name again replaces
 * its own entry; two packages registering one name is a conflict.
 *
 * @template-covariant T of object
 */
final readonly class Entries
{
    /**
     * @param string                                         $kind    what is registered, as a message names it
     * @param array<string, array{origin: string, entry: T}> $entries by name
     */
    public function __construct(private string $kind, private array $entries = [])
    {
    }

    /**
     * @template U of object
     *
     * @param  U         $entry
     * @return self<T|U>
     */
    public function with(string $name, string $origin, object $entry): self
    {
        $entries = $this->entries;
        $entries[$name] = ['origin' => $origin, 'entry' => $entry];

        return new self($this->kind, $entries);
    }

    /** @return T|CannotJudge */
    public function find(string $name): object
    {
        return array_key_exists($name, $this->entries)
            ? $this->entries[$name]['entry']
            : CannotJudge::because(
                sprintf('No %s is registered as "%s".%s', $this->kind, $name, $this->nearest($name)),
            );
    }

    /**
     * The names both register, each said as the sentence the user reads.
     *
     * @param  self<object> $other
     * @return list<string>
     */
    public function conflictsWith(self $other): array
    {
        $conflicts = [];

        foreach ($other->entries as $name => $theirs) {
            $ours = array_key_exists($name, $this->entries) ? $this->entries[$name]['origin'] : $theirs['origin'];

            if ($ours !== $theirs['origin']) {
                $conflicts[] = sprintf(
                    'Two packages register a %s named "%s": %s and %s.',
                    $this->kind,
                    $name,
                    $ours,
                    $theirs['origin'],
                );
            }
        }

        return $conflicts;
    }

    /**
     * These entries and another's; where both name the same thing, the other's.
     *
     * @template U of object
     *
     * @param  self<U>   $other
     * @return self<T|U>
     */
    public function merge(self $other): self
    {
        return new self($this->kind, [...$this->entries, ...$other->entries]);
    }

    /** A registered name a missing one was most likely meant to be, said as a question, or nothing. */
    private function nearest(string $name): string
    {
        $nearest = Nearest::to($name, array_map(strval(...), array_keys($this->entries)));

        return $nearest === '' ? '' : sprintf(' Did you mean "%s"?', $nearest);
    }
}
