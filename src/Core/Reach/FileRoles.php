<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use NightWorksIO\MutationGate\Core\Composer\Manifest;
use NightWorksIO\MutationGate\Core\File\Path;

/**
 * What a file of the repository is to a kill carried across commits
 * (ADR-0008, decision 1): one whose change decides how the gate runs, so
 * that nothing judged before it can stand for the code after it, or a PHP
 * file of a package's tests, whose code the gate follows by name even where
 * it runs when it is loaded. The files that decide are those the layout
 * names (ADR-0005, decision 4, rule 1), and each package's `composer.lock`,
 * which pins what the runner and its plugins are.
 */
final readonly class FileRoles
{
    private function __construct(private Layout $layout, private Packages $packages)
    {
    }

    public static function of(Layout $layout, Packages $packages): self
    {
        return new self($layout, $packages);
    }

    /** Whether a change to this file decides how the gate runs. */
    public function decides(Path $file): bool
    {
        $package = $this->packages->holding($file)->path();

        return $this->layout->decides($file, $package) || $file->equals(Manifest::lockIn($package));
    }

    /** Whether this is a PHP file of its package's tests: a file of test cases, or support. */
    public function isTested(Path $file): bool
    {
        $inPackage = $file->relativeTo($this->packages->holding($file)->path());

        return $this->layout->isTest($inPackage) || $this->layout->isSupport($inPackage);
    }
}
