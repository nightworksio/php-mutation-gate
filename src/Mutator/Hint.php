<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Mutator;

/**
 * The sentence a mutator's survivor gets, in its own terms, such as *No test
 * checks that the action is refused when `Gate::allows()` says no*.
 */
final readonly class Hint
{
    private function __construct(private string $sentence)
    {
    }

    public static function that(string $sentence): self
    {
        return new self($sentence);
    }

    public function sentence(): string
    {
        return $this->sentence;
    }
}
