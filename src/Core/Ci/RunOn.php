<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Proof\Scope;

use function sprintf;
use function str_starts_with;

/**
 * What a CI, or git outside one, says of a run: its ref, which is its proof
 * scope, whether that is a pull request, and the default branch where it is
 * named. On a pull request the scope is `refs/pull/<n>`, and otherwise
 * `refs/heads/<branch>`. A detached `HEAD` has no scope.
 */
final readonly class RunOn
{
    private const string PULL_REQUEST = 'refs/pull/';

    private function __construct(
        private Scope|Detached $scope,
        private Scope|CannotTell $defaultBranch,
        private Revision|Unnamed $commit,
    ) {
    }

    public static function branch(string $branch, Scope|CannotTell $defaultBranch): self|CannotTell
    {
        return self::scoped(sprintf('refs/heads/%s', $branch), $defaultBranch);
    }

    public static function pullRequest(string $number, Scope|CannotTell $defaultBranch): self|CannotTell
    {
        return self::scoped(sprintf('%s%s', self::PULL_REQUEST, $number), $defaultBranch);
    }

    /** A run on a ref already read as a scope, as a plan records it. */
    public static function at(Scope $scope, Scope|CannotTell $defaultBranch): self
    {
        return new self($scope, $defaultBranch, Unnamed::commit());
    }

    /** A run on a detached `HEAD`, which is never a pull request. */
    public static function detached(Scope|CannotTell $defaultBranch): self
    {
        return new self(Detached::head(), $defaultBranch, Unnamed::commit());
    }

    /** A default branch as a CI names it, which it may not. */
    public static function branchNamed(string $branch): Scope|CannotTell
    {
        $scope = Scope::parse(sprintf('refs/heads/%s', $branch));

        return $scope instanceof CannotJudge ? CannotTell::because($scope->why()) : $scope;
    }

    public function scope(): Scope|Detached
    {
        return $this->scope;
    }

    public function isPullRequest(): bool
    {
        return $this->scope instanceof Scope && str_starts_with($this->scope->ref(), self::PULL_REQUEST);
    }

    public function defaultBranch(): Scope|CannotTell
    {
        return $this->defaultBranch;
    }

    /** This run, with the default branch `ci.defaultBranch` or git names, which replaces the CI's own answer. */
    public function withDefaultBranch(Scope $defaultBranch): self
    {
        return new self($this->scope, $defaultBranch, $this->commit);
    }

    /** This run, for the commit the CI says it builds. */
    public function withCommit(Revision $commit): self
    {
        return new self($this->scope, $this->defaultBranch, $commit);
    }

    /** The commit the CI says the run builds, where it names one. */
    public function commit(): Revision|Unnamed
    {
        return $this->commit;
    }

    /**
     * This run, as the checkout at this commit may take it. A run on the
     * default branch whose checkout is not the commit the CI names has no
     * scope, so it writes nothing the default branch will trust.
     */
    public function atCheckout(Revision $head): self
    {
        $named = $this->commit instanceof Revision && $this->commit->name() !== $head->name();
        $onDefault = $this->scope instanceof Scope
            && $this->defaultBranch instanceof Scope
            && $this->scope->equals($this->defaultBranch);

        $scope = $named && $onDefault ? Detached::head() : $this->scope;

        return new self($scope, $this->defaultBranch, Unnamed::commit());
    }

    private static function scoped(string $ref, Scope|CannotTell $defaultBranch): self|CannotTell
    {
        $scope = Scope::parse($ref);

        return $scope instanceof CannotJudge
            ? CannotTell::because($scope->why())
            : new self($scope, $defaultBranch, Unnamed::commit());
    }
}
