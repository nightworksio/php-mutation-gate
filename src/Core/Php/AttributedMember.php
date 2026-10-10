<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use NightWorksIO\MutationGate\Core\Hold\Standing;

/**
 * One run of attribute groups written together: where each attribute's name
 * stands among the file's tokens, and what the run stands on, with the class,
 * method or function it names, `<class>::<method>` for a method.
 */
final readonly class AttributedMember
{
    /** @param list<int> $names */
    private function __construct(private array $names, private Standing $standing, private string $holder)
    {
    }

    /** @param list<int> $names */
    public static function of(array $names, Standing $standing, string $holder): self
    {
        return new self($names, $standing, $holder);
    }

    /** @return list<int> */
    public function names(): array
    {
        return $this->names;
    }

    public function standing(): Standing
    {
        return $this->standing;
    }

    /** The class, method or function the run stands on; empty where it stands on none with a name. */
    public function holder(): string
    {
        return $this->holder;
    }
}
