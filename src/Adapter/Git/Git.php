<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_map;
use function count;
use function getenv;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\Change\JudgedCommit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\Repository;

use function sprintf;
use function trim;

/**
 * The repository as git sees it from a directory: what changed from the merge
 * base of a revision and HEAD to what is on disk, every file that is not
 * ignored with its blob id, and a file as it was at a revision. Uncommitted
 * changes and untracked files count; the gate's own workspace,
 * `.mutation-gate`, never does, nor is it fingerprinted. It also says the
 * commit HEAD is at, whether the working tree holds anything it does not, a
 * file marked assume-unchanged or skip-worktree counting as holding more,
 * since what is on disk there is not what git shows, the branch it is on,
 * the branch the remote calls its default, and the URL that remote fetches
 * from.
 *
 * A revision is resolved to its commit once, the first time a file is read
 * at it, so every file read at it is read at the same commit, and the files
 * read at it together are read in one batch.
 */
final readonly class Git implements ChangeSource, Repository
{
    /** The ref that names the remote's default branch. */
    private const string ORIGIN_HEAD = 'refs/remotes/origin/HEAD';

    /** How the remote's branches are spelt. */
    private const string ORIGIN = 'refs/remotes/origin/';

    private const string NO_ORIGIN_HEAD = '%s points at no branch, so git cannot name the default branch.';

    private Objects $objects;

    private function __construct(private Command $git, private Root $directory)
    {
        $this->objects = new Objects($git);
    }

    /** The repository from a directory, git itself never seeing what every run withholds. */
    public static function at(string $directory): self
    {
        $root = Root::of($directory);

        return new self(Command::in($root->value()), $root);
    }

    /** The repository from a directory, git itself never seeing what a run withholds. */
    public static function withholding(string $directory, Withheld $withheld): self
    {
        $root = Root::of($directory);

        return new self(Command::withholding($root->value(), $withheld, getenv()), $root);
    }

    public function changesSince(Revision $base): Changes|CannotTell
    {
        $mergeBase = $this->git->run(['merge-base', '--end-of-options', $base->name(), 'HEAD']);

        return $mergeBase instanceof CannotTell ? $mergeBase : $this->changesFrom(Revision::ref(trim($mergeBase)));
    }

    public function changesFrom(Revision $commit): Changes|CannotTell
    {
        $resolved = $this->objects->of($commit);

        return $resolved instanceof CannotTell ? $resolved : Changed::from($this->git, $this->workingTree(), $resolved);
    }

    public function judged(Revision $commit): JudgedCommit|CannotTell
    {
        return $this->objects->judged($commit);
    }

    public function readable(JudgedCommit $judged): Revision|CannotTell
    {
        return $this->objects->readable($judged);
    }

    public function fingerprints(): Fingerprints|CannotTell
    {
        $listed = $this->git->run([
            'ls-files',
            '--cached',
            '--others',
            '--exclude-standard',
            '-z',
            ...OutsideTheWorkspace::pathspec(),
        ]);

        return $listed instanceof CannotTell ? $listed : $this->workingTree()->fingerprints(Diff::paths($listed));
    }

    public function unstaged(): Paths|CannotTell
    {
        $changed = $this->git->run(['diff', '--name-only', '--no-renames', '-z']);
        $untracked = $this->git->run(Untracked::command());

        return match (true) {
            $changed instanceof CannotTell => $changed,
            $untracked instanceof CannotTell => $untracked,
            default => Paths::of(...array_map(Path::of(...), [...Diff::paths($changed), ...Diff::paths($untracked)])),
        };
    }

    /** @return ByPath<Instant>|CannotTell */
    public function lastChanged(Paths $paths): ByPath|CannotTell
    {
        if (count($paths) === 0) {
            return ByPath::none();
        }

        $printed = $this->git->feed(History::arguments(), History::input($paths));

        return $printed instanceof CannotTell ? $printed : History::lastChanged($printed, $paths);
    }

    public function fileAt(Path $path, Revision $revision): Contents|Missing|CannotTell
    {
        $files = $this->filesAt(Paths::of($path), $revision);

        return $files instanceof CannotTell ? $files : $files->at($path, Missing::at($path));
    }

    /** @return ByPath<Contents|Missing>|CannotTell */
    public function filesAt(Paths $paths, Revision $revision): ByPath|CannotTell
    {
        if ($revision->isWorkingTree()) {
            return ByPath::mapping($paths, $this->workingTree()->read(...));
        }

        $commit = $this->objects->of($revision);

        return $commit instanceof CannotTell ? $commit : Blobs::at($this->git, $commit)->of($paths);
    }

    public function head(): Revision|CannotTell
    {
        $head = $this->git->run(['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']);
        $commit = $head instanceof CannotTell ? $head : Commit::parse(trim($head));

        return $commit instanceof Commit ? $commit->revision() : $commit;
    }

    public function isClean(): bool|CannotTell
    {
        $flagged = $this->git->run(['ls-files', '-v', '-z']);

        if ($flagged instanceof CannotTell || Diff::hidesChanges($flagged)) {
            return $flagged instanceof CannotTell ? $flagged : false;
        }

        $status = $this->git->run([
            '--no-optional-locks',
            'status',
            '--porcelain',
            '--untracked-files=all',
            ...OutsideTheWorkspace::pathspec(),
        ]);

        return $status instanceof CannotTell ? $status : $status === '';
    }

    /** Whether the clone is shallow: it holds only the newest commits, and not the history before them. */
    public function isShallow(): bool|CannotTell
    {
        return $this->objects->isShallow();
    }

    public function branch(): Scope|Detached|CannotTell
    {
        $name = $this->git->run(['rev-parse', '--abbrev-ref', 'HEAD']);

        return match (true) {
            $name instanceof CannotTell => $name,
            trim($name) === Revision::HEAD => Detached::head(),
            default => RunOn::branchNamed(trim($name)),
        };
    }

    public function defaultBranch(): Scope|CannotTell
    {
        $target = $this->git->run(['symbolic-ref', '--quiet', self::ORIGIN_HEAD]);

        return $target instanceof CannotTell
            ? CannotTell::because(sprintf(self::NO_ORIGIN_HEAD, self::ORIGIN_HEAD))
            : RunOn::branchNamed(mb_substr(trim($target), mb_strlen(self::ORIGIN)));
    }

    /** The URL git's `origin` remote fetches from, as git spells it. */
    public function originUrl(): string|CannotTell
    {
        $url = $this->git->run(['remote', 'get-url', 'origin']);

        return $url instanceof CannotTell ? $url : trim($url);
    }

    private function workingTree(): WorkingTree
    {
        return WorkingTree::of($this->git, $this->directory);
    }
}
