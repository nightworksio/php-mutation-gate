<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use NightWorksIO\MutationGate\Core\File\Fingerprints;

/**
 * What a content key reads outside the test directories: every file there by
 * its digest, less the exceptions, and each CI definition that runs the gate
 * as it runs. It keeps every file there too, exceptions and all, since a
 * unit's own source is what it is whatever a key leaves out, and names the
 * files that define the runner, which decide a unit's mutant set.
 */
final readonly class Source
{
    private function __construct(
        private Fingerprints $files,
        private CiDefinitions $ci,
        private Fingerprints $outside,
        private Fingerprints $definitions,
    ) {
    }

    /**
     * @param Fingerprints $outsideTests every file outside the test directories, tracked or untracked and not
     *                                   ignored, as it is on disk
     */
    public static function of(Fingerprints $outsideTests, CiDefinitions $ci, Exceptions $exceptions): self
    {
        $files = [];
        $definitions = [];

        foreach ($outsideTests as $file) {
            if (! $exceptions->leaveOut($file->path()) && ! $ci->has($file->path())) {
                $files[] = $file;
            }

            if ($exceptions->defines($file->path())) {
                $definitions[] = $file;
            }
        }

        return new self(Fingerprints::of(...$files), $ci, $outsideTests, Fingerprints::of(...$definitions));
    }

    /** The files outside the test directories that define the runner, such as its own config. */
    public function definitions(): Fingerprints
    {
        return $this->definitions;
    }

    /** Every file outside the test directories, the exceptions and CI definitions among them. */
    public function outside(): Fingerprints
    {
        return $this->outside;
    }

    public function files(): Fingerprints
    {
        return $this->files;
    }

    public function ci(): CiDefinitions
    {
        return $this->ci;
    }
}
