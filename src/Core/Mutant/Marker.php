<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/**
 * A runner's own ignore marker (ADR-0008): where it is, what it says, and the
 * `ignores.entries` entry that replaces it. A marker hides mutants with no
 * reason and no end, so a run refuses it unless `ignores.native` allows it.
 */
final readonly class Marker
{
    /** The entry that replaces a marker in source: one per mutant it hides, each with its reason. */
    private const string PER_MUTANT
        = '{"mutant": "<the id of each mutant it hides>", "reason": "<why no test can tell>"}';

    private function __construct(private string $where, private string $marker, private string $replacement)
    {
    }

    public static function of(string $where, string $marker, string $replacement): self
    {
        return new self($where, $marker, $replacement);
    }

    /** A marker written in source, at a file and its line, which an entry per mutant it hides replaces. */
    public static function inSource(string $where, string $marker): self
    {
        return new self($where, $marker, self::PER_MUTANT);
    }

    /** Where the marker is: a file and its line, or a runner's config file and the key it is under. */
    public function where(): string
    {
        return $this->where;
    }

    /** The marker as it is written. */
    public function marker(): string
    {
        return $this->marker;
    }

    /** The `ignores.entries` entry that does what the marker does, with a reason and an end. */
    public function replacement(): string
    {
        return $this->replacement;
    }
}
