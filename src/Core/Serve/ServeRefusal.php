<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Serve;

/**
 * Why a daemon of `mutation-gate serve` ran no command for a request
 * (ADR-0078); the client then runs the command itself.
 *
 * @internal
 */
final readonly class ServeRefusal
{
    private function __construct(private string $why)
    {
    }

    public static function because(string $why): self
    {
        return new self($why);
    }

    public function why(): string
    {
        return $this->why;
    }
}
