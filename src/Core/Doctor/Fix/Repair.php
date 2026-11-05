<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor\Fix;

use function array_values;

use NightWorksIO\MutationGate\Core\Troubleshooting\Slug;

/**
 * What `doctor --fix` does about one finding (ADR-0083): the changes it makes
 * to files, or why it makes none, such as a package it would have to install, which
 * it names and never installs.
 *
 * @internal
 */
final readonly class Repair
{
    /** @param list<Change> $changes */
    private function __construct(private Slug $slug, private array $changes, private string $refusal)
    {
    }

    public static function changing(Slug $slug, Change ...$changes): self
    {
        return new self($slug, array_values($changes), '');
    }

    /** No edit, for this reason, said as what to do in its place. */
    public static function refused(Slug $slug, string $why): self
    {
        return new self($slug, [], $why);
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    /** @return list<Change> */
    public function changes(): array
    {
        return $this->changes;
    }

    /** Why it changes nothing; empty where it changes a file. */
    public function refusal(): string
    {
        return $this->refusal;
    }

    public function isRefused(): bool
    {
        return $this->refusal !== '';
    }
}
