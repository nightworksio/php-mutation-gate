<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use function array_all;
use function array_values;

use NightWorksIO\MutationGate\Core\Ci\Variables;

/**
 * What a proof store needs to write its ledgers (ADR-0007 decision 5): the
 * environment variables a job must hold, every one of them. A job without
 * them opens the store read-only (ADR-0013 decision 14).
 */
final readonly class Credentials
{
    /** @param list<string> $variables */
    private function __construct(private array $variables)
    {
    }

    /** A store that needs each of these variables set. */
    public static function of(string ...$variables): self
    {
        return new self(array_values($variables));
    }

    /** A store that needs none, as a directory does. */
    public static function none(): self
    {
        return new self([]);
    }

    /** Whether this environment holds every variable the store needs. */
    public function heldIn(Variables $environment): bool
    {
        return array_all($this->variables, fn(string $variable): bool => $environment->has($variable));
    }
}
