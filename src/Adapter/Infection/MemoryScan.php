<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Infection;

use function dirname;
use function fclose;
use function fopen;
use function fwrite;
use function getenv;
use function getmypid;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Uncapped;

use function rename;
use function rmdir;
use function scandir;
use function sprintf;
use function unlink;

/**
 * The directory of the ini file that caps a run's memory (ADR-0004, decision
 * 9), for every PHP process of the run to scan: Infection's own, and each
 * mutant's PHPUnit, all of which inherit the environment. It is in
 * Infection's own directory of the gate's workspace, one for each process of
 * the gate, so two runs in one checkout never share it, and it is removed
 * when the run is done. Its writing is the Pest adapter's too, which no
 * adapter may share.
 */
final readonly class MemoryScan
{
    /** In Infection's own directory, by the id of the gate's process. */
    private const string DIRECTORY = 'php/%d';

    private function __construct(private string|Uncapped $directory)
    {
    }

    /** The directory this process of the gate writes the cap of a run to. */
    public static function directoryIn(Project $project): string
    {
        return $project->own(sprintf(self::DIRECTORY, getmypid()));
    }

    /** The cap written into Infection's own directory, or none where it caps nothing; or why it cannot be written. */
    public static function in(Project $project, MemoryCap $memory): self|CannotJudge
    {
        if (! $memory->caps()) {
            return new self(Uncapped::Memory);
        }

        $directory = self::directoryIn($project);
        $ini = sprintf('%s/%s', $directory, MemoryCap::FILE);

        return self::fresh($project->workspace(), $directory) && self::written($directory, $ini, $memory)
            ? new self($directory)
            : CannotJudge::because(sprintf(MemoryCap::UNWRITTEN, $ini));
    }

    /** A command whose PHP processes scan the cap's directory too, after those they scan already. */
    public function onto(Command $command): Command
    {
        return $this->directory instanceof Uncapped
            ? $command
            : $command->with([
                MemoryCap::SCAN_DIR => MemoryCap::scanning(getenv(MemoryCap::SCAN_DIR), $this->directory),
            ]);
    }

    /**
     * Removes the cap's directory once the run that scans it is done; a run
     * with no cap has none. A directory something made inside it is left,
     * with the cap's directory, for the next run of the process to refuse.
     */
    public function remove(): void
    {
        if ($this->directory instanceof Uncapped || ! is_dir($this->directory) || is_link($this->directory)) {
            return;
        }

        $kept = false;

        foreach (self::entries($this->directory) as $entry) {
            $file = is_link($entry) || is_file($entry);
            $kept = $kept || ! $file;

            if ($file) {
                unlink($entry);
            }
        }

        if (! $kept) {
            rmdir($this->directory);
        }
    }

    /**
     * Whether the cap's directory is there and holds nothing but files it
     * may clear: made where it is not, emptied of what an earlier process of
     * the same id left. A link at any level from the gate's directory down to
     * it, or anything but a file in it, is refused: making it would follow a
     * link, and clearing it would reach beyond it.
     */
    private static function fresh(string $workspace, string $directory): bool
    {
        return match (true) {
            self::linkedBetween($workspace, $directory) => false,
            is_dir($directory) => self::cleared($directory),
            default => mkdir($directory, recursive: true),
        };
    }

    /** Whether a directory from the gate's directory down to this one, both included, is a link. */
    private static function linkedBetween(string $workspace, string $directory): bool
    {
        $level = $directory;

        while (! is_link($level) && $level !== $workspace && dirname($level) !== $level) {
            $level = dirname($level);
        }

        return is_link($level);
    }

    /** Whether the directory is emptied: it holds nothing but files, which it clears. */
    private static function cleared(string $directory): bool
    {
        $entries = self::entries($directory);

        foreach ($entries as $entry) {
            if (is_link($entry) || ! is_file($entry)) {
                return false;
            }
        }

        foreach ($entries as $entry) {
            unlink($entry);
        }

        return true;
    }

    /**
     * Whether the cap is written into its fresh directory, whole: staged
     * beside it, then moved into place, so a PHP that starts meanwhile reads
     * no half of one. Opening the staged file refuses one that is there.
     */
    private static function written(string $directory, string $ini, MemoryCap $memory): bool
    {
        $handle = fopen(sprintf('%s/%s', $directory, MemoryCap::STAGED), 'x');

        return $handle !== false
            && fwrite($handle, $memory->ini()) !== false
            && fclose($handle)
            && rename(sprintf('%s/%s', $directory, MemoryCap::STAGED), $ini);
    }

    /** @return list<string> the paths of what the directory holds */
    private static function entries(string $directory): array
    {
        $names = scandir($directory);
        $entries = [];

        foreach ($names === false ? [] : $names as $name) {
            $entries = $name === '.' || $name === '..' ? $entries : [...$entries, sprintf('%s/%s', $directory, $name)];
        }

        return $entries;
    }
}
