<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Watch;

use function array_any;
use function array_values;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

/**
 * The files `watch` polls, those in a tree and those in a test directory,
 * each by the digest of what it holds, so a file written again as it was
 * changes nothing (ADR-0010, decision 1).
 */
final readonly class Watched
{
    /** @param list<SuiteDirectory> $tests */
    private function __construct(private Trees $trees, private array $tests, private Fingerprints $files)
    {
    }

    /** The files in these trees and test directories, none seen yet. */
    public static function in(Trees $trees, SuiteDirectory ...$tests): self
    {
        return new self($trees, array_values($tests), Fingerprints::none());
    }

    /** The watched files among these, as they are now. */
    public function at(Fingerprints $files): self
    {
        $watched = Fingerprints::none();

        foreach ($files as $file) {
            $watched = $this->watches($file->path()) ? $watched->with($file) : $watched;
        }

        return new self($this->trees, $this->tests, $watched);
    }

    /**
     * How the watched files changed since then: each new one added, each gone
     * one deleted, and each that holds something else modified.
     */
    public function changesSince(self $then): Changes
    {
        $changes = Changes::none();

        foreach ($this->files as $file) {
            $digest = $then->files->digestOf($file->path());
            $changes = match (true) {
                ! $digest instanceof Digest => $changes->with(Change::added($file->path(), Lines::none())),
                $digest->value() !== $file->digest()->value() => $changes->with(
                    Change::modified($file->path(), Lines::none()),
                ),
                default => $changes,
            };
        }

        foreach ($then->files as $file) {
            $changes = $this->files->digestOf($file->path()) instanceof Digest
                ? $changes
                : $changes->with(Change::deleted($file->path()));
        }

        return $changes;
    }

    private function watches(Path $file): bool
    {
        return $this->trees->holding($file) instanceof Tree || $this->inTests($file);
    }

    private function inTests(Path $file): bool
    {
        return array_any($this->tests, static fn(SuiteDirectory $tests): bool => $tests->holds($file));
    }
}
