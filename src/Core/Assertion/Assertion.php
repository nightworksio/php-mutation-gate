<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Assertion;

/**
 * One assertion a test makes, as it is written, such as `assertNotNull` or
 * `->not->toBeNull()`, what it checks, and the style it is written in.
 */
final readonly class Assertion
{
    private function __construct(private string $written, private AssertionKind $kind, private AssertionStyle $style)
    {
    }

    public static function of(string $written, AssertionKind $kind, AssertionStyle $style): self
    {
        return new self($written, $kind, $style);
    }

    public function written(): string
    {
        return $this->written;
    }

    public function kind(): AssertionKind
    {
        return $this->kind;
    }

    public function style(): AssertionStyle
    {
        return $this->style;
    }
}
