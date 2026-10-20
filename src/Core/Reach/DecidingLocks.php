<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\ChangeKind;
use NightWorksIO\MutationGate\Core\Composer\DecidingPackages;
use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;

/**
 * Each package's `composer.lock`, as it decides how the gate runs (ADR-0030):
 * a change to one decides only where it moved a package `reach.lockPackages`
 * names, or a key besides the package lists, or where either version cannot
 * be read.
 */
final readonly class DecidingLocks
{
    private function __construct(private Packages $packages, private DecidingPackages $deciding)
    {
    }

    /** The locks of these packages, deciding by the packages these name. */
    public static function in(Packages $packages, DecidingPackages $deciding): self
    {
        return new self($packages, $deciding);
    }

    /** Whether a file is the lock of the package that holds it. */
    public function isLock(Path $file): bool
    {
        return $file->equals(Manifest::lockIn($this->packages->holding($file)->path()));
    }

    /** Whether a lock, as it was and as it is, decides how the gate runs: a lock that is missing either way does. */
    public function decides(Contents|Missing $before, Contents|Missing $now): bool
    {
        return ! $before instanceof Contents || ! $now instanceof Contents || $this->deciding->decide($before, $now);
    }

    /**
     * These sources, with the lock this change touched in its place marked
     * deciding alike where what it moved decides nothing.
     */
    public function marking(Sources $sources, Change $change): Sources
    {
        $path = $change->path();

        return $change->kind() === ChangeKind::Modified
            && $this->isLock($path)
            && ! $this->decides($sources->before($path), $sources->now($path))
            ? $sources->decidingAlike($path)
            : $sources;
    }
}
