<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Push;

use function mb_strlen;

use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Variables;

use function sprintf;
use function str_repeat;

/**
 * The push the pre-commit framework runs a `pre-push` hook for, as git's
 * line for it (ADR-0024, decision 13). The framework reads git's lines
 * itself, and hands a hook the first ref that sends commits: the commit the
 * remote holds, or the parent of the first commit it does not, as
 * `PRE_COMMIT_FROM_REF`, and the commit pushed as `PRE_COMMIT_TO_REF`. Where
 * the remote holds none of the ref's history, it names the refs alone, and
 * the line pushes the commit the working tree is at over no commit. A ref it
 * does not name is written `HEAD`, which only the gate's messages show.
 */
final readonly class PreCommitPush
{
    /** The commit the change is read since. */
    private const string FROM_REF = 'PRE_COMMIT_FROM_REF';

    /** The commit pushed. */
    private const string TO_REF = 'PRE_COMMIT_TO_REF';

    /** The local ref pushed, as git names it. */
    private const string LOCAL_BRANCH = 'PRE_COMMIT_LOCAL_BRANCH';

    /** The remote ref pushed to, as git names it. */
    private const string REMOTE_BRANCH = 'PRE_COMMIT_REMOTE_BRANCH';

    /** A line as git writes it. */
    private const string LINE = '%s %s %s %s';

    /** How git names no commit. */
    private const string NO_COMMIT = '0';

    /** Git's line for the push the framework names, the working tree at this commit; none where it names none. */
    public static function line(Variables $environment, Revision $head): string
    {
        $local = $environment->has(self::LOCAL_BRANCH) ? $environment->valueOf(self::LOCAL_BRANCH) : Revision::HEAD;
        $remote = $environment->has(self::REMOTE_BRANCH) ? $environment->valueOf(self::REMOTE_BRANCH) : Revision::HEAD;

        return match (true) {
            $environment->has(self::FROM_REF) && $environment->has(self::TO_REF) => sprintf(
                self::LINE,
                $local,
                $environment->valueOf(self::TO_REF),
                $remote,
                $environment->valueOf(self::FROM_REF),
            ),
            $environment->has(self::LOCAL_BRANCH) => sprintf(
                self::LINE,
                $local,
                $head->name(),
                $remote,
                str_repeat(self::NO_COMMIT, mb_strlen($head->name())),
            ),
            default => '',
        };
    }
}
