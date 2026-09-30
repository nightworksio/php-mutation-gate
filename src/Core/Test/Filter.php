<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Test;

/** The tests a runner's `--filter` pattern selects, such as the tests a `#[Holds]` names. */
final readonly class Filter
{
    /** A pattern that matches no name: a lookahead that can never hold. */
    private const string NOTHING = '(?!)';

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
}
