<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

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
 * 9), for every PHP process of the run to scan: Pest, each mutant's own
 * process, and each run of a mutant judged by reference. It is beside the
 * run's results, one for each process of the gate, so two runs in one
 * checkout never share it, and it is removed when the run is done. Its
 * writing is the Infection adapter's too, which no adapter may share.
 */
final readonly class MemoryScan
{
    /** Beside the results, by the id of the gate's process. */
    private const string DIRECTORY = '%s/php/%d';

    private function __construct(private string|Uncapped $directory)
    {
    }

    /** The directory this process of the gate writes the cap of a run to, by the run's results file. */
    public static function directoryBeside(string $results): string
    {
        return sprintf(self::DIRECTORY, dirname($results), getmypid());
    }

    /** The cap written beside a run's results file, or none where it caps nothing; or why it cannot be written. */
    public static function beside(string $results, MemoryCap $memory): self|CannotJudge
    {
        if (! $memory->caps()) {
            return new self(Uncapped::Memory);
        }

        $directory = self::directoryBeside($results);
        $ini = sprintf('%s/%s', $directory, MemoryCap::FILE);

        return self::fresh($directory) && self::written($directory, $ini, $memory)
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

    /** Removes the cap's directory once the run that scans it is done; a run with no cap has none. */
    public function remove(): void
    {
        if ($this->directory instanceof Uncapped || ! is_dir($this->directory) || is_link($this->directory)) {
            return;
        }

        foreach (self::entries($this->directory) as $entry) {
            unlink($entry);
        }

        rmdir($this->directory);
    }

    /**
     * Whether the cap's directory is there and holds nothing but files it
     * may clear: made where it is not, emptied of what an earlier process of
     * the same id left. A link where it or a directory above it in the
     * workspace should be, or anything but a file in it, is refused.
     */
    private static function fresh(string $directory): bool
    {
        return match (true) {
            is_link($directory) || is_link(dirname($directory)) || is_link(dirname($directory, 2)) => false,
            is_dir($directory) => self::cleared($directory),
            default => mkdir($directory, recursive: true),
        };
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
