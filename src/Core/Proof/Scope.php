<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof;

use NightWorksIO\MutationGate\Core\CannotJudge;

use function preg_match;
use function sprintf;

/** Whose ledger it is: a ref, `refs/heads/<branch>` or `refs/pull/<n>`. */
final readonly class Scope
{
    /** A branch's ref, and the branch's name. */
    private const string BRANCH = '#^refs/heads/(.+)$#sD';

    /** A pull request's ref, with nothing but its number. */
    private const string PULL_REQUEST = '#^refs/pull/[1-9]\d*$#D';

    /**
     * What git's `check-ref-format` refuses in a branch's name: `..`, `@{`, an
     * empty segment, a control character, a space, any of `~^:?*[\`, a segment
     * that begins with `.` or ends in `.lock`, a name that begins with `/` or
     * ends with `/` or `.`, and `@` alone. A backslash and `..` would lead a
     * store's path out of its directory, and git never names a branch so.
     */
    private const string REFUSED = '#\.\.|@\{|//|[\x00-\x20\x7F~^:?*\[\\\\]|(?:^|/)\.|\.lock(?:/|$)|^/|[/.]$|^@$#D';

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
        $isBranch = preg_match(self::BRANCH, $ref, $branch) === 1 && preg_match(self::REFUSED, $branch[1]) !== 1;

        if (! $isBranch && preg_match(self::PULL_REQUEST, $ref) !== 1) {
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

    /** The scope as a reader says it: a branch by its name, anything else as its ref. */
    public function name(): string
    {
        return preg_match(self::BRANCH, $this->ref, $branch) === 1 ? $branch[1] : $this->ref;
    }

    public function equals(self $other): bool
    {
        return $this->ref === $other->ref;
    }
}
