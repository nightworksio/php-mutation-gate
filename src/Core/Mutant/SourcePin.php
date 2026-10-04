<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

use function sprintf;

/**
 * What a test that reads the source must find there to kill a mutant no
 * behavioural test can: a call to a function, by its name, as `hash_equals(`
 * (ADR-0021, decision 19).
 */
final readonly class SourcePin
{
    private function __construct(private string $function)
    {
    }

    /** A call to this function, which the test asserts the mutant's file still makes. */
    public static function call(string $function): self
    {
        return new self($function);
    }

    /** The text the test expects in the file: the function's name and its opening parenthesis. */
    public function text(): string
    {
        return sprintf('%s(', $this->function);
    }
}
