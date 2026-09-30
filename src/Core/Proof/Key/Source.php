<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use NightWorksIO\MutationGate\Core\File\Fingerprints;

/**
 * What a content key reads outside the test directories: every file there by
 * its digest, less the exceptions, and each CI definition that runs the gate
 * as it runs.
 */
final readonly class Source
{
    private function __construct(private Fingerprints $files, private CiDefinitions $ci)
    {
    }

    /**
     * @param Fingerprints $outsideTests every file outside the test directories, tracked or untracked and not
     *                                   ignored, as it is on disk
     */
    public static function of(Fingerprints $outsideTests, CiDefinitions $ci, Exceptions $exceptions): self
    {
        $files = [];

        foreach ($outsideTests as $file) {
            if (! $exceptions->leaveOut($file->path()) && ! $ci->has($file->path())) {
                $files[] = $file;
            }
        }

        return new self(Fingerprints::of(...$files), $ci);
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
