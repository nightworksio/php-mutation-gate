<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_all;
use function array_any;
use function array_merge;
use function array_values;

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Listed;

/**
 * The environment variables a proof store reads its credentials from
 * (ADR-0007 decision 5): those it needs to write, every one of a set of
 * them, where any of several sets will do, and those it reads beside them
 * where they are set. A job without a set it needs opens the store
 * read-only (ADR-0013 decision 14).
 */
final readonly class Credentials
{
    /**
     * @param list<list<string>> $needed   each set of variables that is enough to write
     * @param list<string>       $optional
     */
    private function __construct(private array $needed, private array $optional)
    {
    }

    /** A store that needs each of these variables set to write. */
    public static function needing(string ...$variables): self
    {
        return new self([array_values($variables)], []);
    }

    /** A store that needs none, as a directory does. */
    public static function none(): self
    {
        return new self([[]], []);
    }

    /** These, or each of these variables set instead. */
    public function orNeeding(string ...$variables): self
    {
        return new self([...$this->needed, array_values($variables)], $this->optional);
    }

    /** These, and these variables the store reads besides where they are set. */
    public function reading(string ...$variables): self
    {
        return new self($this->needed, [...$this->optional, ...array_values($variables)]);
    }

    /** Whether this environment holds every variable of a set the store needs. */
    public function heldIn(Variables $environment): bool
    {
        return array_any(
            $this->needed,
            static fn(array $set): bool => array_all($set, $environment->has(...)),
        );
    }

    /**
     * Every variable the store reads, those it needs first.
     *
     * @return Listed<string>
     */
    public function variables(): Listed
    {
        return Listed::of(...array_merge(...$this->needed), ...$this->optional);
    }
}
