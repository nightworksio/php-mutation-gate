<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hint;

use function array_slice;

/**
 * A mutant's change as tokens, each spelt as written: the original's and the
 * mutant's significant tokens, where the original's tokens that differ begin
 * and end, and where the original expression around them begins and ends
 * (see Change), each end exclusive.
 *
 * @internal
 */
final readonly class ChangeTokens
{
    /**
     * @param list<string>    $before the original's tokens
     * @param list<string>    $after  the mutant's tokens
     * @param array{int, int} $differs
     * @param array{int, int} $around
     */
    public function __construct(
        private array $before,
        private array $after,
        private array $differs,
        private array $around,
    ) {
    }

    /** @return list<string> */
    public function before(): array
    {
        return $this->before;
    }

    /** @return list<string> */
    public function after(): array
    {
        return $this->after;
    }

    /** @return array{int, int} */
    public function differs(): array
    {
        return $this->differs;
    }

    /** @return array{int, int} */
    public function around(): array
    {
        return $this->around;
    }

    /**
     * The original's tokens that differ.
     *
     * @return list<string>
     */
    public function changed(): array
    {
        return array_slice($this->before, $this->differs[0], $this->differs[1] - $this->differs[0]);
    }
}
