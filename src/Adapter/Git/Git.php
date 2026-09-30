<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_key_exists;
use function array_map;
use function array_pop;
use function count;
use function explode;
use function getenv;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Commit;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Lines;
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
use function str_starts_with;
use function trim;

/**
 * The repository as git sees it from a directory: what changed from the merge
 * base of a revision and HEAD to what is on disk, every file that is not
 * ignored with its blob id, and a file as it was at a revision. Uncommitted
 * changes and untracked files count. It also says the commit HEAD is at, the
 * branch it is on, the branch the remote calls its default, and the URL
 * that remote fetches from.
 *
 * A revision is resolved to its commit once, the first time a file is read
 * at it, so every file read at it is read at the same commit, and the files
 * read at it together are read in one batch.
 */
final class Git implements ChangeSource, Repository
{
    /** What git names the branch of a detached `HEAD`. */
    private const string DETACHED = 'HEAD';

    /** The ref that names the remote's default branch. */
    private const string ORIGIN_HEAD = 'refs/remotes/origin/HEAD';

    /** How the remote's branches are spelt. */
    private const string ORIGIN = 'refs/remotes/origin/';

    private const string NO_ORIGIN_HEAD = '%s points at no branch, so git cannot name the default branch.';

    /** Why a revision cannot be read from. */
    private const string UNKNOWN = '%s is not a revision this repository has.';


    /** @var array<string, string|CannotTell> each revision read at, by its name: its commit, or why it has none */
    private array $commits = [];

    private function __construct(private readonly Command $git, private readonly Root $directory)
    {
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

        return $mergeBase instanceof CannotTell ? $mergeBase : $this->changesFrom(trim($mergeBase));
    }

    public function fingerprints(): Fingerprints|CannotTell
    {
        $listed = $this->git->run(['ls-files', '--cached', '--others', '--exclude-standard', '-z']);

        return $listed instanceof CannotTell ? $listed : $this->workingTree()->fingerprints($this->paths($listed));
    }

    public function unstaged(): Paths|CannotTell
    {
        $changed = $this->git->run(['diff', '--name-only', '--no-renames', '-z']);
        $untracked = $this->git->run(['ls-files', '--others', '--exclude-standard', '-z']);

        return match (true) {
            $changed instanceof CannotTell => $changed,
            $untracked instanceof CannotTell => $untracked,
            default => Paths::of(...array_map(Path::of(...), [...$this->paths($changed), ...$this->paths($untracked)])),
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

        $commit = $this->commitOf($revision);

        return $commit instanceof CannotTell ? $commit : Blobs::at($this->git, $commit)->of($paths);
    }

    public function head(): Revision|CannotTell
    {
        $head = $this->git->run(['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']);
        $commit = $head instanceof CannotTell ? $head : Commit::parse(trim($head));

        return $commit instanceof Commit ? $commit->revision() : $commit;
    }

    public function branch(): Scope|Detached|CannotTell
    {
        $name = $this->git->run(['rev-parse', '--abbrev-ref', 'HEAD']);

        return match (true) {
            $name instanceof CannotTell => $name,
            trim($name) === self::DETACHED => Detached::head(),
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
        $url = $this->git->run(['remote', 'get-url', '--', 'origin']);

        return $url instanceof CannotTell ? $url : trim($url);
    }

    /**
     * The commit a revision names, resolved the first time it is asked for. A
     * name that begins with `-` is refused, since git would read it as an
     * option, and `--end-of-options` keeps any other from being read as one.
     */
    private function commitOf(Revision $revision): string|CannotTell
    {
        $name = $revision->name();

        if (! array_key_exists($name, $this->commits)) {
            $printed = str_starts_with($name, '-') ? $name : $this->git->run($this->resolving($name));
            $parsed = $printed instanceof CannotTell ? $printed : Commit::parse(trim($printed));
            $this->commits[$name] = $parsed instanceof Commit
                ? $parsed->id()
                : CannotTell::because(sprintf(self::UNKNOWN, $name));
        }

        return $this->commits[$name];
    }

    /**
     * What asks git for the commit a name resolves to, and for nothing else.
     *
     * @return list<string>
     */
    private function resolving(string $name): array
    {
        return ['rev-parse', '--verify', '--quiet', '--end-of-options', sprintf('%s^{commit}', $name)];
    }

    private function changesFrom(string $commit): Changes|CannotTell
    {
        $printed = $this->printed([
            ['diff', '--find-renames', '--relative', '--name-status', '-z', $commit],
            ['diff', '--no-ext-diff', '--find-renames', '--relative', '--unified=0', $commit],
            ['ls-files', '--others', '--exclude-standard', '-z'],
        ]);

        if ($printed instanceof CannotTell) {
            return $printed;
        }

        [$status, $patch, $untracked] = $printed;

        return $this->withUntracked(Diff::changes($status, Diff::lines($patch)), $untracked);
    }

    /**
     * What git printed for each of these, or why it could not answer one.
     *
     * @param  list<list<string>> $commands
     * @return list<string>|CannotTell
     */
    private function printed(array $commands): array|CannotTell
    {
        $printed = [];

        foreach ($commands as $arguments) {
            $output = $this->git->run($arguments);

            if ($output instanceof CannotTell) {
                return $output;
            }

            $printed[] = $output;
        }

        return $printed;
    }

    private function withUntracked(Changes $changes, string $untracked): Changes
    {
        $added = [];

        foreach ($this->paths($untracked) as $path) {
            $read = $this->workingTree()->read(Path::of($path));
            $lines = $read instanceof Contents ? Diff::whole($read->text()) : Lines::none();
            $added[] = Change::added(Path::of($path), $lines);
        }

        return Changes::of(...$changes, ...$added);
    }

    private function workingTree(): WorkingTree
    {
        return WorkingTree::of($this->git, $this->directory);
    }

    /**
     * The paths of a `-z` listing, each ended by a NUL.
     *
     * @return list<string>
     */
    private function paths(string $listed): array
    {
        $paths = explode("\0", $listed);
        array_pop($paths);

        return $paths;
    }

}
