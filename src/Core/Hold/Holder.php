<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hold;

use NightWorksIO\MutationGate\Core\Test\TestMethod;

use function preg_quote;
use function sprintf;
use function str_contains;

/** What a `#[Holds]` stands on: a test class, or one of its methods, written `Class::method`. */
final readonly class Holder
{
    private function __construct(private string $written)
    {
    }

    /** A holder as the reader of `#[Holds]` names it. */
    public static function of(string $written): self
    {
        return new self($written);
    }

    /** Whether it is a method, rather than a whole class. */
    public function isMethod(): bool
    {
        return str_contains($this->written, TestMethod::SEPARATOR);
    }

    /**
     * What a PHPUnit `--filter` names to select its tests: each test of a
     * class, or the method itself and its data sets.
     */
    public function filtered(): string
    {
        return $this->isMethod()
            ? sprintf('%s\b', preg_quote($this->written, '/'))
            : sprintf('%s%s', preg_quote($this->written, '/'), TestMethod::SEPARATOR);
    }

    public function written(): string
    {
        return $this->written;
    }
}
