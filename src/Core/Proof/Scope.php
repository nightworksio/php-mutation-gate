<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_match;
use function sprintf;

/** Whose ledger it is: a ref, `refs/heads/<branch>` or `refs/pull/<n>`. */
final readonly class Scope
{
    /**
     * A branch's ref, with no whitespace, no empty segment and no `.` or `..`
     * segment, or a pull request's, with nothing but its number.
     */
    private const string REF = '#^refs/(?:heads/(?!(?:.*/)?\.{1,2}(?:/|$))(?!.*//)[^/\s]\S*(?<!/)|pull/[1-9]\d*)$#D';

    private function __construct(private string $ref)
    {
    }

    public static function of(string $ref): self
    {
        return new self($ref);
    }

    public static function branch(string $branch): self
    {
        return new self(sprintf('refs/heads/%s', $branch));
    }

    public static function pullRequest(int $number): self
    {
        return new self(sprintf('refs/pull/%d', $number));
    }

    /**
     * A scope as a ref spells it, refused where it is neither a branch's nor
     * a pull request's, or would lead out of its store's directory.
     */
    public static function parse(string $ref): self|CannotJudge
    {
        if (preg_match(self::REF, $ref) !== 1) {
            return CannotJudge::because(sprintf(
                '"%s" is not a scope. A scope is refs/heads/<branch> or refs/pull/<number>.',
                $ref,
            ));
        }

        return new self($ref);
    }

    public function ref(): string
    {
        return $this->ref;
    }

    public function equals(self $other): bool
    {
        return $this->ref === $other->ref;
    }
}
