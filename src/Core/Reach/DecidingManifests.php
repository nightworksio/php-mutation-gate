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
 * Each package's `composer.json` and `composer.lock`, as they decide how the
 * gate runs: a change to a lock decides only where it moved a package
 * `reach.lockPackages` names, or a key besides the package lists (ADR-0030);
 * a change to a manifest only where it moved such a package's line, or a key
 * besides the package lists and those that only describe (ADR-0056); and
 * either where a version cannot be read.
 *
 * @internal
 */
final readonly class DecidingManifests
{
    private function __construct(private Packages $packages, private DecidingPackages $deciding)
    {
    }

    /** The manifests and locks of these packages, deciding by the packages these name. */
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

    /** Whether a file is the manifest of the package that holds it. */
    public function isManifest(Path $file): bool
    {
        return $file->equals(Manifest::fileIn($this->packages->holding($file)->path()));
    }

    /** Whether a manifest, as it was and as it is, decides how the gate runs: one missing either way does. */
    public function manifestDecides(Contents|Missing $before, Contents|Missing $now): bool
    {
        return ! $before instanceof Contents
            || ! $now instanceof Contents
            || $this->deciding->decideManifest($before, $now);
    }

    /**
     * These sources, with the manifest or lock this change touched in its
     * place marked deciding alike where what it moved decides nothing.
     */
    public function marking(Sources $sources, Change $change): Sources
    {
        return $change->kind() === ChangeKind::Modified && ! $this->decidesAt($sources, $change->path())
            ? $sources->decidingAlike($change->path())
            : $sources;
    }

    /** Whether a change to a file decides how the gate runs, as a manifest or a lock; any other file does. */
    private function decidesAt(Sources $sources, Path $path): bool
    {
        return match (true) {
            $this->isLock($path) => $this->decides($sources->before($path), $sources->now($path)),
            $this->isManifest($path) => $this->manifestDecides($sources->before($path), $sources->now($path)),
            default => true,
        };
    }
}
