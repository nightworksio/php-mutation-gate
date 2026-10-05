<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Recheck;

/**
 * Why a run re-checks no survivors first, in one line (ADR-0020, decision
 * 19). A run that plans and judges in one process says so only where a
 * pull request's run would expect a re-check: where the branch has no
 * earlier run, or its last run left no survivor.
 */
final readonly class NoRecheck
{
    private const string OFF = 'survivorsFirst.max is 0, so no survivor is re-checked first.';

    private const string DEFAULT_BRANCH = 'A run of the default branch re-checks no survivors first.';

    private const string NO_SCOPE = 'The run has no ref of its own, so it has no earlier run to re-check.';

    private const string NO_EARLIER_RUN = 'No earlier run of this branch to re-check.';

    private const string NONE_LEFT = 'The last run of this branch left no survivor to re-check.';

    private function __construct(private string $why, private bool $expected)
    {
    }

    public static function off(): self
    {
        return new self(self::OFF, expected: false);
    }

    public static function onTheDefaultBranch(): self
    {
        return new self(self::DEFAULT_BRANCH, expected: false);
    }

    public static function withoutAScope(): self
    {
        return new self(self::NO_SCOPE, expected: false);
    }

    public static function noEarlierRun(): self
    {
        return new self(self::NO_EARLIER_RUN, expected: true);
    }

    public static function noneLeft(): self
    {
        return new self(self::NONE_LEFT, expected: true);
    }

    public function why(): string
    {
        return $this->why;
    }

    /** Whether a pull request's run would have expected a re-check, so a run that goes on says why there was none. */
    public function wasExpected(): bool
    {
        return $this->expected;
    }
}
