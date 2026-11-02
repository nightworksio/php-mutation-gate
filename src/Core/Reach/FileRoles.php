<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function is_string;

use NightWorksIO\MutationGate\Core\Composer\DecidingPackages;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * What a file of the repository is to a kill carried across commits
 * (ADR-0008), where a change to it cannot be followed by name:
 * one whose change decides how the gate runs, so that nothing judged before
 * it can stand for the code after it, or one Composer's autoloader loads in
 * every process, which acts on code that never names it. The files that
 * decide are those the layout names (ADR-0005, rule 1), and each
 * package's `composer.lock`, which pins what the runner and its plugins are,
 * where its change moved a package that decides (ADR-0030).
 *
 * @internal
 */
final readonly class FileRoles
{
    /**
     * @param Paths $loaded every file a `composer.json` of the repository lists under `files` in its `autoload` or
     *                      `autoload-dev`
     */
    private function __construct(
        private Layout $layout,
        private Packages $packages,
        private DecidingLocks $locks,
        private Paths $loaded,
    ) {
    }

    /**
     * @param Paths $loaded every file a `composer.json` of the repository lists under `files` in its `autoload` or
     *                      `autoload-dev`
     */
    public static function of(Layout $layout, Packages $packages, Paths $loaded, DecidingPackages $deciding): self
    {
        return new self($layout, $packages, DecidingLocks::in($packages, $deciding), $loaded);
    }

    /** Whether a file is the lock of the package that holds it, whose versions decide whether its change decides. */
    public function isLock(Path $file): bool
    {
        return $this->locks->isLock($file);
    }

    /**
     * Whether a change to this file, from what it held to what it holds,
     * decides how the gate runs: every change does where the config reads a
     * file it cannot name, a lock's by what it moved, and any other file's
     * where the layout names it.
     */
    public function decides(Path $file, Contents|Missing $before, Contents|Missing $now): bool
    {
        return match (true) {
            is_string($this->layout->unnamed()) => true,
            $this->locks->isLock($file) => $this->locks->decides($before, $now),
            default => $this->layout->decides($file, $this->packages->holding($file)->path()),
        };
    }

    /** Whether Composer's autoloader loads this file in every process, as a `composer.json` lists it under `files`. */
    public function isLoadedEverywhere(Path $file): bool
    {
        return $this->loaded->has($file);
    }
}
