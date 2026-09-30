<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Runtime;

use function dirname;
use function fclose;
use function fopen;
use function fwrite;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;

use NightWorksIO\MutationGate\Core\File\DiskPath;
use NightWorksIO\MutationGate\Core\Runner\CapFiles;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;

use function rename;
use function rmdir;
use function scandir;
use function sprintf;
use function unlink;

/**
 * A run's memory cap, written as an ini file into a directory on disk, and
 * removed from it, for both runner adapters, each of which lays out where
 * the directory is.
 */
final readonly class CapDirectory implements CapFiles
{
    public function written(DiskPath $workspace, DiskPath $directory, MemoryCap $cap): bool
    {
        return $this->fresh($workspace->value(), $directory->value()) && $this->staged($directory->value(), $cap);
    }

    public function removed(DiskPath $directory): void
    {
        $at = $directory->value();

        if (! is_dir($at) || is_link($at)) {
            return;
        }

        $kept = false;

        foreach ($this->entries($at) as $entry) {
            $file = is_link($entry) || is_file($entry);
            $kept = $kept || ! $file;

            if ($file) {
                unlink($entry);
            }
        }

        if (! $kept) {
            rmdir($at);
        }
    }

    /**
     * Whether the directory is there and holds nothing but files it may
     * clear: made where it is not, emptied of what an earlier run left.
     * Making it where a level is a link would follow the link, and clearing
     * anything but files would reach beyond it.
     */
    private function fresh(string $workspace, string $directory): bool
    {
        return match (true) {
            $this->linkedBetween($workspace, $directory) => false,
            is_dir($directory) => $this->cleared($directory),
            default => mkdir($directory, recursive: true),
        };
    }

    /** Whether a directory from the gate's directory down to this one, both included, is a link. */
    private function linkedBetween(string $workspace, string $directory): bool
    {
        $level = $directory;

        while (! is_link($level) && $level !== $workspace && dirname($level) !== $level) {
            $level = dirname($level);
        }

        return is_link($level);
    }

    /** Whether the directory is emptied: it holds nothing but files, which it clears. */
    private function cleared(string $directory): bool
    {
        $entries = $this->entries($directory);

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
    private function staged(string $directory, MemoryCap $cap): bool
    {
        $staged = sprintf('%s/%s', $directory, MemoryCap::STAGED);
        $handle = fopen($staged, 'x');

        return $handle !== false
            && fwrite($handle, $cap->ini()) !== false
            && fclose($handle)
            && rename($staged, sprintf('%s/%s', $directory, MemoryCap::FILE));
    }

    /** @return list<string> the paths of what the directory holds */
    private function entries(string $directory): array
    {
        $names = scandir($directory);
        $entries = [];

        foreach ($names === false ? [] : $names as $name) {
            $entries = $name === '.' || $name === '..' ? $entries : [...$entries, sprintf('%s/%s', $directory, $name)];
        }

        return $entries;
    }
}
