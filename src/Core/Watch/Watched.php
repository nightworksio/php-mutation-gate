<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Watch;

use function array_any;
use function array_filter;
use function array_unique;
use function array_values;
use function hash;
use function implode;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Looked;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Fingerprint;
use NightWorksIO\MutationGate\Core\File\Fingerprints;
use NightWorksIO\MutationGate\Core\File\Lines;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Test\SuiteDirectory;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sort;
use function sprintf;

/**
 * The files `watch` polls, those in a tree and those in a test directory,
 * each by the digest of what it holds, so a file written again as it was
 * changes nothing (ADR-0010); and the look they were last seen by, which
 * the next look starts from (ADR-0120).
 *
 * @internal
 */
final readonly class Watched
{
    /** A test file in the digest of them all: its path and its own digest. */
    private const string ENTRY = '%s %s';

    private const string HASH = 'xxh128';

    /** @param list<SuiteDirectory> $tests */
    private function __construct(
        private Trees $trees,
        private array $tests,
        private Fingerprints $files,
        private Looked $looked,
    ) {
    }

    /** The files in these trees and test directories, none seen yet. */
    public static function in(Trees $trees, SuiteDirectory ...$tests): self
    {
        return new self($trees, array_values($tests), Fingerprints::none(), Looked::none());
    }

    /** The watched files among these, as they are now; the next look is taken whole. */
    public function at(Fingerprints $files): self
    {
        return $this->after(Looked::whole($files));
    }

    /** The watched files among those this look saw, which the next look starts from. */
    public function after(Looked $looked): self
    {
        $watched = array_filter(
            [...$looked->files()],
            fn(Fingerprint $file): bool => $this->watches($file->path()),
        );

        return new self($this->trees, $this->tests, Fingerprints::of(...$watched), $looked);
    }

    /**
     * The directories it watches, each once: each tree's and each test directory's.
     *
     * @return list<string>
     */
    public function directories(): array
    {
        $directories = [];

        foreach ($this->trees as $tree) {
            $directories[] = $tree->path()->value();
        }

        foreach ($this->tests as $tests) {
            $directories[] = $tests->path()->value();
        }

        return array_values(array_unique($directories));
    }

    /** The digest of the files in the test directories, which moves where one is added, changed or gone. */
    public function testsDigest(): string
    {
        $digests = [];

        foreach ($this->files as $file) {
            $digests = $this->inTests($file->path())
                ? [...$digests, sprintf(self::ENTRY, $file->path()->value(), $file->digest()->value())]
                : $digests;
        }

        sort($digests);

        return hash(self::HASH, implode("\n", $digests));
    }

    /** The look these files were seen by. */
    public function looked(): Looked
    {
        return $this->looked;
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
