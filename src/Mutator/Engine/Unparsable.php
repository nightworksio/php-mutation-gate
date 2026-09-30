<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator\Engine;

/**
 * Code the parser refused, with the parser's first error.
 *
 * @internal the engine's own
 */
final readonly class Unparsable
{
    private function __construct(private string $reason)
    {
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }

    /** The parser's first error, as it words it. */
    public function reason(): string
    {
        return $this->reason;
    }
}
