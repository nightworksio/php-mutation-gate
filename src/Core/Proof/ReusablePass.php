<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;

use function sprintf;

/**
 * A pass a run may reuse where every input its run read is identical to the
 * run's own (ADR-0198), and where it was read: a scope's ledger, or the
 * ledger a pull request's own run wrote.
 *
 * @internal
 */
final readonly class ReusablePass
{
    private function __construct(private Passed $passed, private string $from)
    {
    }

    /** A pass a scope's ledger records. */
    public static function inScope(Passed $passed, Scope $scope): self
    {
        return new self($passed, $scope->ref());
    }

    /** A pass the ledger of a pull request's own run records. */
    public static function ofPullRequest(Passed $passed, PullRequestNumber $pullRequest): self
    {
        return new self($passed, sprintf('pull request #%d', $pullRequest->value()));
    }

    public function passed(): Passed
    {
        return $this->passed;
    }

    /** Where the pass was read, as a person names it. */
    public function from(): string
    {
        return $this->from;
    }
}
