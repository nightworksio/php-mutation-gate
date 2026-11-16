<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine\Schema;

/**
 * A site a schema switches, how it takes its place, and its mutated code on
 * one line (ADR-0091).
 *
 * @internal the engine's own
 */
final readonly class Placed
{
    private function __construct(private Site $site, private SiteKind $kind, private string $mutated)
    {
    }

    public static function of(Site $site, SiteKind $kind, string $mutated): self
    {
        return new self($site, $kind, $mutated);
    }

    public function id(): int
    {
        return $this->site->id();
    }

    public function start(): int
    {
        return $this->site->start();
    }

    public function end(): int
    {
        return $this->site->end();
    }

    /**
     * Where it sorts among the sites of a file: by where it starts, the
     * longest first.
     *
     * @return array{int, int}
     */
    public function order(): array
    {
        return [$this->start(), -$this->end()];
    }

    /**
     * Whether it stands wholly within another that starts no later than it
     * does, as a site sorted after another does, or spans the same.
     */
    public function isWithin(self $other): bool
    {
        return $this->end() <= $other->end();
    }

    /** The switch in place of this original code, this site's mutant in force where its id is on. */
    public function switched(SwitchCall $switch, string $original): string
    {
        return $this->kind->switched($switch, $this->site->id(), $this->mutated, $original);
    }
}
