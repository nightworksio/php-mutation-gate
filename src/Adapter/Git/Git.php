<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_pop;
use function explode;
use function file_get_contents;
use function implode;
use function is_file;
use function is_string;
use function mb_strlen;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\Detached;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Port\ChangeSource;
use NightWorksIO\MutationGate\Port\Repository;

use function sprintf;
use function str_contains;
use function trim;

/**
 * The repository as git sees it from a directory: what changed from the merge
 * base of a revision and HEAD to what is on disk, every file that is not
 * ignored with its blob id, and a file as it was at a revision. Uncommitted
 * changes and untracked files count. It also says the commit HEAD is at, the
 * branch it is on, and the branch the remote calls its default.
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

    /** Why a file's name cannot be handed to git one per line. */
    private const string LINE_BREAK = 'git cannot hash "%s" with the others, because its name holds a line break.';

    /** @var array<string, string|CannotTell> each revision read at, by its name: its commit, or why it has none */
    private array $commits = [];

    private function __construct(private readonly Command $git, private readonly Root $directory)
    {
    }

    /** The repository from a directory, as the registration spells it (owner: flows). */
    public static function at(string $directory): self
    {
        $root = Root::of($directory);

        return new self(Command::in($root->value()), $root);
    }

    public function changesSince(Revision $base): Changes|CannotTell
    {
        $mergeBase = $this->git->run(['merge-base', '--end-of-options', $base->name(), 'HEAD']);

        return $mergeBase instanceof CannotTell ? $mergeBase : $this->changesFrom(trim($mergeBase));
    }

    public function fingerprints(): Fingerprints|CannotTell
    {
        $listed = $this->git->run(['ls-files', '--cached', '--others', '--exclude-standard', '-z']);

        return $listed instanceof CannotTell ? $listed : $this->hashed($this->onDisk($listed));
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
            return ByPath::mapping($paths, $this->read(...));
        }

        $commit = $this->commitOf($revision);

        return $commit instanceof CannotTell ? $commit : Blobs::at($this->git, $commit)->of($paths);
    }

    public function head(): Revision|CannotTell
    {
        $head = $this->git->run(['rev-parse', '--verify', '--quiet', 'HEAD^{commit}']);

        return $head instanceof CannotTell ? $head : Revision::ref(trim($head));
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

    /** The commit a revision names, resolved the first time it is asked for. */
    private function commitOf(Revision $revision): string|CannotTell
    {
        if (! array_key_exists($revision->name(), $this->commits)) {
            $commit = $this->git->run(['rev-parse', sprintf('%s^{commit}', $revision->name())]);
            $this->commits[$revision->name()] = $commit instanceof CannotTell
                ? CannotTell::because(sprintf(self::UNKNOWN, $revision->name()))
                : trim($commit);
        }

        return $this->commits[$revision->name()];
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
            $read = $this->read(Path::of($path));
            $lines = $read instanceof Contents ? Diff::whole($read->text()) : Lines::none();
            $added[] = Change::added(Path::of($path), $lines);
        }

        return Changes::of(...$changes, ...$added);
    }

    /**
     * The listed paths that are files on disk.
     *
     * @return array<int, string>
     */
    private function onDisk(string $listed): array
    {
        return array_filter(
            $this->paths($listed),
            fn(string $path): bool => is_file($this->directory->at(Path::of($path))->value()),
        );
    }

    /**
     * Every file with the blob id git gives what it holds on disk, hashed in
     * one batch.
     *
     * @param array<int, string> $paths
     */
    private function hashed(array $paths): Fingerprints|CannotTell
    {
        if ($paths === []) {
            return Fingerprints::none();
        }

        $input = $this->input($paths);
        $hashed = $input instanceof CannotTell
            ? $input
            : $this->git->feed(['hash-object', '--no-filters', '--stdin-paths'], $input);

        return $hashed instanceof CannotTell ? $hashed : Fingerprints::of(...array_map(
            static fn(string $path, string $digest): Fingerprint => Fingerprint::of(
                Path::of($path),
                Digest::of($digest),
            ),
            $paths,
            $this->lines($hashed),
        ));
    }

    /**
     * The paths as git reads them from its input, one per line.
     *
     * @param array<int, string> $paths
     */
    private function input(array $paths): string|CannotTell
    {
        foreach ($paths as $path) {
            if (str_contains($path, "\n")) {
                return CannotTell::because(sprintf(self::LINE_BREAK, $path));
            }
        }

        return sprintf("%s\n", implode("\n", $paths));
    }

    /** What a file on disk holds, or that it is missing where it is no file or cannot be read. */
    private function read(Path $path): Contents|Missing
    {
        $file = $this->directory->at($path)->value();
        $text = is_file($file) ? file_get_contents($file) : false;

        return is_string($text) ? Contents::of($text) : Missing::at($path);
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

    /**
     * The lines of what git printed, each ended by a line break.
     *
     * @return list<string>
     */
    private function lines(string $printed): array
    {
        $lines = explode("\n", $printed);
        array_pop($lines);

        return $lines;
    }
}
