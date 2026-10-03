<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Http;

use function sprintf;

/** A bearer token a request carries. It is a credential: nothing prints it. */
final readonly class Token
{
    private function __construct(private string $value)
    {
    }

    public static function bearer(string $value): self
    {
        return new self($value);
    }

    /** The `Authorization` header's value that carries it. */
    public function authorization(): string
    {
        return sprintf('Bearer %s', $this->value);
    }
}
