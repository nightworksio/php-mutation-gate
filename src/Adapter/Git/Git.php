<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Git;

use function array_filter;
use function array_map;
use function array_pop;
use function explode;
use function file_get_contents;
use function implode;
use function is_file;
use function is_string;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Port\ChangeSource;

use function sprintf;
use function str_contains;
use function trim;

/**
 * The repository as git sees it from a directory: what changed from the merge
 * base of a revision and HEAD to what is on disk, every file that is not
 * ignored with its blob id, and a file as it was at a revision. Uncommitted
 * changes and untracked files count.
 */
final readonly class Git implements ChangeSource
{
    /** Why a revision cannot be read from. */
    private const string UNKNOWN = '%s is not a revision this repository has.';

    /** Why a file's name cannot be handed to git one per line. */
    private const string LINE_BREAK = 'git cannot hash "%s" with the others, because its name holds a line break.';

    private function __construct(private Command $git, private string $directory)
    {
    }

    public static function at(string $directory): self
    {
        return new self(Command::in($directory), $directory);
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
        if ($revision->isWorkingTree()) {
            return $this->read($path);
        }

        $commit = $this->git->run(['rev-parse', sprintf('%s^{commit}', $revision->name())]);

        if ($commit instanceof CannotTell) {
            return CannotTell::because(sprintf(self::UNKNOWN, $revision->name()));
        }

        $blob = $this->git->run(['cat-file', 'blob', sprintf('%s:./%s', trim($commit), $path->value())]);

        return is_string($blob) ? Contents::of($blob) : Missing::at($path);
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
        foreach ($this->paths($untracked) as $path) {
            $read = $this->read(Path::of($path));
            $lines = $read instanceof Contents ? Diff::whole($read->text()) : Lines::none();
            $changes = $changes->with(Change::added(Path::of($path), $lines));
        }

        return $changes;
    }

    /**
     * The listed paths that are files on disk.
     *
     * @return array<int, string>
     */
    private function onDisk(string $listed): array
    {
        return array_filter($this->paths($listed), fn(string $path): bool => is_file($this->pathTo($path)));
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

    private function read(Path $path): Contents|Missing
    {
        $file = $this->pathTo($path->value());
        $text = is_file($file) ? file_get_contents($file) : false;

        return is_string($text) ? Contents::of($text) : Missing::at($path);
    }

    private function pathTo(string $path): string
    {
        return sprintf('%s/%s', $this->directory, $path);
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
