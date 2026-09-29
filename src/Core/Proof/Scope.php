<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

/** Whose ledger it is: a ref, such as `refs/heads/main` or `refs/pull/12`. */
final readonly class Scope
{
    private function __construct(private string $ref) {}

    public static function of(string $ref): self
    {
        return new self($ref);
    }

    public function ref(): string
    {
        return $this->ref;
    }
}
