<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Import;

use function sprintf;

/** One key of another tool's config, and what became of it in the gate's config. */
final readonly class Carried
{
    private function __construct(private string $key, private Fate $fate, private string $said)
    {
    }

    /** A key the gate's config now holds, and how it holds it: `the floor of every tree, 80`. */
    public static function imported(string $key, string $as): self
    {
        return new self($key, Fate::Imported, $as);
    }

    /** A key the other tool still reads, and why. */
    public static function stays(string $key, string $because): self
    {
        return new self($key, Fate::Stays, $because);
    }

    /** A key the gate has no use for, and why. */
    public static function dropped(string $key, string $because): self
    {
        return new self($key, Fate::Dropped, $because);
    }

    public function key(): string
    {
        return $this->key;
    }

    public function fate(): Fate
    {
        return $this->fate;
    }

    /** The key and what became of it, as the report lists it, the other tool's config being this file. */
    public function said(string $file): string
    {
        $fate = match ($this->fate) {
            Fate::Imported => sprintf('imported as %s', $this->said),
            Fate::Stays => sprintf('stays in %s, because %s', $file, $this->said),
            Fate::Dropped => sprintf('dropped, because %s', $this->said),
        };

        return sprintf('%s: %s', $this->key, $fate);
    }
}
