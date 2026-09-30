<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_all;
use function array_values;

use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Config\Listed;

/**
 * The environment variables a proof store reads its credentials from
 * (ADR-0007 decision 5): those it needs to write, every one of them, and
 * those it reads beside them where they are set. A job without the needed
 * ones opens the store read-only (ADR-0013 decision 14).
 */
final readonly class Credentials
{
    /**
     * @param list<string> $needed
     * @param list<string> $optional
     */
    private function __construct(private array $needed, private array $optional)
    {
    }

    /** A store that needs each of these variables set to write. */
    public static function needing(string ...$variables): self
    {
        return new self(array_values($variables), []);
    }

    /** A store that needs none, as a directory does. */
    public static function none(): self
    {
        return new self([], []);
    }

    /** These, and these variables the store reads besides where they are set. */
    public function reading(string ...$variables): self
    {
        return new self($this->needed, [...$this->optional, ...array_values($variables)]);
    }

    /** Whether this environment holds every variable the store needs. */
    public function heldIn(Variables $environment): bool
    {
        return array_all($this->needed, static fn(string $variable): bool => $environment->has($variable));
    }

    /**
     * Every variable the store reads, those it needs first.
     *
     * @return Listed<string>
     */
    public function variables(): Listed
    {
        return Listed::of(...$this->needed, ...$this->optional);
    }
}
