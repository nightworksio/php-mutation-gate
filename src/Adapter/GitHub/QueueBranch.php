<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\GitHub;

use NightWorksIO\MutationGate\Core\Ci\PullRequestNumber;
use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;

/**
 * The branch GitHub's merge queue builds a group on,
 * `gh-readonly-queue/<base branch>/pr-<number>-<base commit>`: the base
 * branch, slashes and all, then the pull request the group was built for and
 * the commit it was built on (ADR-0022, ADR-0049).
 *
 * @internal
 */
final readonly class QueueBranch
{
    /** Where every branch a merge queue builds lives. */
    public const string PREFIX = 'gh-readonly-queue/';

    /** A queue's branch, as a ref or by its name, capturing the pull request's number. */
    private const string BRANCH = '#^(?:refs/heads/)?gh-readonly-queue/.+/pr-(?<number>[1-9][0-9]*)-[0-9a-f]{40,64}$#D';

    private function __construct(private PullRequestNumber $pullRequest)
    {
    }

    /** The queue's branch a ref or branch name spells; not given where it spells none. */
    public static function parse(string $ref): self|NotGiven
    {
        $number = preg_match(self::BRANCH, $ref, $found) === 1
            ? PullRequestNumber::parse($found['number'])
            : NotGiven::value();

        return $number instanceof PullRequestNumber ? new self($number) : NotGiven::value();
    }

    /** The pull request the group was built for. */
    public function pullRequest(): PullRequestNumber
    {
        return $this->pullRequest;
    }
}
