<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;

/**
 * What a file of the repository is to a kill carried across commits
 * (ADR-0008, decision 1), where a change to it cannot be followed by name:
 * one whose change decides how the gate runs, so that nothing judged before
 * it can stand for the code after it, or one Composer's autoloader loads in
 * every process, which acts on code that never names it. The files that
 * decide are those the layout names (ADR-0005, decision 4, rule 1), and each
 * package's `composer.lock`, which pins what the runner and its plugins are.
 */
final readonly class FileRoles
{
    /**
     * @param Paths $loaded every file a `composer.json` of the repository lists under `files` in its `autoload` or
     *                      `autoload-dev`
     */
    private function __construct(private Layout $layout, private Packages $packages, private Paths $loaded)
    {
    }

    /**
     * @param Paths $loaded every file a `composer.json` of the repository lists under `files` in its `autoload` or
     *                      `autoload-dev`
     */
    public static function of(Layout $layout, Packages $packages, Paths $loaded): self
    {
        return new self($layout, $packages, $loaded);
    }

    /** Whether a change to this file decides how the gate runs. */
    public function decides(Path $file): bool
    {
        $package = $this->packages->holding($file)->path();

        return $this->layout->decides($file, $package) || $file->equals(Manifest::lockIn($package));
    }

    /** Whether Composer's autoloader loads this file in every process, as a `composer.json` lists it under `files`. */
    public function isLoadedEverywhere(Path $file): bool
    {
        return $this->loaded->has($file);
    }
}
