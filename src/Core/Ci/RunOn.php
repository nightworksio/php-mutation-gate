<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Proof\Scope;

use function sprintf;
use function str_starts_with;

/**
 * What a CI says of the run it started: its ref, which is its proof scope,
 * whether that is a pull request, and the default branch where the CI names
 * it. On a pull request the scope is `refs/pull/<n>`, and otherwise
 * `refs/heads/<branch>`.
 */
final readonly class RunOn
{
    private const string PULL_REQUEST = 'refs/pull/';

    private function __construct(private Scope $scope, private Scope|CannotTell $defaultBranch)
    {
    }

    public static function branch(string $branch, Scope|CannotTell $defaultBranch): self|CannotTell
    {
        return self::scoped(sprintf('refs/heads/%s', $branch), $defaultBranch);
    }

    public static function pullRequest(string $number, Scope|CannotTell $defaultBranch): self|CannotTell
    {
        return self::scoped(sprintf('%s%s', self::PULL_REQUEST, $number), $defaultBranch);
    }

    /** A default branch as a CI names it, which it may not. */
    public static function branchNamed(string $branch): Scope|CannotTell
    {
        $scope = Scope::parse(sprintf('refs/heads/%s', $branch));

        return $scope instanceof CannotJudge ? CannotTell::because($scope->why()) : $scope;
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function isPullRequest(): bool
    {
        return str_starts_with($this->scope->ref(), self::PULL_REQUEST);
    }

    public function defaultBranch(): Scope|CannotTell
    {
        return $this->defaultBranch;
    }

    private static function scoped(string $ref, Scope|CannotTell $defaultBranch): self|CannotTell
    {
        $scope = Scope::parse($ref);

        return $scope instanceof CannotJudge ? CannotTell::because($scope->why()) : new self($scope, $defaultBranch);
    }
}
