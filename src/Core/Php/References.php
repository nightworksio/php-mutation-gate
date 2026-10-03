<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

/**
 * Where the project reads a value, and whether it may read it where no token
 * says so: through a variable class, a `constant()` of a name that is not
 * written out, a subclass's own constant, or a chain of declarations too long
 * to follow.
 */
final readonly class References
{
    /** @param list<Site> $sites */
    private function __construct(private array $sites, private bool $ambiguous)
    {
    }

    public static function none(): self
    {
        return new self([], ambiguous: false);
    }

    public static function at(Site $site): self
    {
        return new self([$site], ambiguous: false);
    }

    /** No site, but a read the scan cannot follow. */
    public static function unknown(): self
    {
        return new self([], ambiguous: true);
    }

    public function and(self $other): self
    {
        return new self([...$this->sites, ...$other->sites], $this->ambiguous || $other->ambiguous);
    }

    /** The same ambiguity, with no site. */
    public function withoutSites(): self
    {
        return new self([], $this->ambiguous);
    }

    /** @return list<Site> */
    public function sites(): array
    {
        return $this->sites;
    }

    public function isAmbiguous(): bool
    {
        return $this->ambiguous;
    }
}
