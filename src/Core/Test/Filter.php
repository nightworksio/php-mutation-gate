<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;

/** The tests a runner's `--filter` pattern selects, such as the tests a `#[Holds]` names. */
final readonly class Filter
{
    /** A pattern that matches no name: a lookahead that can never hold. */
    private const string NOTHING = '(?!)';

    /** How PHPUnit wraps a filter that is no whole expression of its own, less its flag, which compiles alike. */
    private const string WRAPPED = '{%s}';

    private function __construct(private string $pattern)
    {
    }

    /** The filter that selects no test: a run narrowed to it starts, loads its tests and runs none. */
    public static function nothing(): self
    {
        return new self(self::NOTHING);
    }

    public static function matching(string $pattern): self
    {
        return new self($pattern);
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    /**
     * Whether PCRE compiles the pattern as PHPUnit reads it: as written, or
     * wrapped. One it cannot compile, such as one past PCRE's size limit,
     * matches no name, so a run narrowed to it runs no test.
     */
    public function compiles(): bool
    {
        return $this->compilable($this->pattern) || $this->compilable(sprintf(self::WRAPPED, $this->pattern));
    }

    /** Whether PCRE compiles the expression; one it cannot is also raised as a warning the answer makes needless. */
    private function compilable(string $expression): bool
    {
        set_error_handler(static fn(): bool => true, E_WARNING);

        try {
            return preg_match($expression, '') !== false;
        } finally {
            restore_error_handler();
        }
    }
}
