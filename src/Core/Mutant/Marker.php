<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Order\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;

use function sprintf;

/**
 * A runner's own ignore marker (ADR-0008): where it is, the function it is
 * in, what it says, and the `ignores.entries` entry that replaces it. A marker hides mutants with no
 * reason and no end, so a run refuses it unless `ignores.native` allows it.
 */
final readonly class Marker
{
    /** The entry that replaces a marker in source: one per mutant it hides, each with its reason. */
    private const string PER_MUTANT
        = '{"mutant": "<the id of each mutant it hides>", "reason": "<why no test can tell>"}';

    private function __construct(
        private string $where,
        private string $marker,
        private string $replacement,
        private Enclosing|Nameless $enclosing,
    ) {
    }

    /** A marker outside the source, such as a key of a runner's config, which is in no function. */
    public static function of(string $where, string $marker, string $replacement): self
    {
        return new self($where, $marker, $replacement, Nameless::code());
    }

    /**
     * A marker written in source, at a file and its line, in the function it
     * is in or documents, which an entry per mutant it hides replaces.
     */
    public static function inSource(Path $file, Line $line, string $marker, Enclosing|Nameless $enclosing): self
    {
        return new self(sprintf('%s:%d', $file->value(), $line->number()), $marker, self::PER_MUTANT, $enclosing);
    }

    /** Where the marker is: a file and its line, or a runner's config file and the key it is under. */
    public function where(): string
    {
        return $this->where;
    }

    /** The named function the marker is in or documents, or nameless code where it speaks for none. */
    public function enclosing(): Enclosing|Nameless
    {
        return $this->enclosing;
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
