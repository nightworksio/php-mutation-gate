<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

/**
 * One decision about what a change reaches, as the sentence printed for it,
 * and whether it reaches every unit of a package.
 */
final readonly class Reason
{
    private function __construct(private string $text, private bool $widens)
    {
    }

    public static function that(string $text): self
    {
        return new self($text, widens: false);
    }

    /** This decision, as one that reaches every unit of a package or of every package. */
    public function widening(): self
    {
        return new self($this->text, widens: true);
    }

    /** Whether the decision reaches every unit of a package or of every package. */
    public function widens(): bool
    {
        return $this->widens;
    }

    public function text(): string
    {
        return $this->text;
    }
}
