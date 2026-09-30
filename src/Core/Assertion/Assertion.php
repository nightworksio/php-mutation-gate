<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

/** One assertion a test makes, as it is written, such as `assertNotNull` or `->not->toBeNull()`, and what it checks. */
final readonly class Assertion
{
    private function __construct(private string $written, private AssertionKind $kind)
    {
    }

    public static function of(string $written, AssertionKind $kind): self
    {
        return new self($written, $kind);
    }

    public function written(): string
    {
        return $this->written;
    }

    public function kind(): AssertionKind
    {
        return $this->kind;
    }
}
