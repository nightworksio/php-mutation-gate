<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Proof\Key;

use NightWorksIO\MutationGate\Core\File\Fingerprints;

/**
 * What a content key reads outside the test directories: every file there by
 * its digest, less the exceptions, and the one CI definition that runs the
 * gate as it runs.
 */
final readonly class Source
{
    private function __construct(private Fingerprints $files, private CiDefinition $ci)
    {
    }

    /**
     * @param Fingerprints $outsideTests every file outside the test directories, tracked or untracked and not
     *                                   ignored, as it is on disk
     */
    public static function of(Fingerprints $outsideTests, CiDefinition $ci, Exceptions $exceptions): self
    {
        $files = Fingerprints::none();

        foreach ($outsideTests as $file) {
            $read = ! $exceptions->leaveOut($file->path()) && ! $file->path()->equals($ci->path());
            $files = $read ? $files->with($file) : $files;
        }

        return new self($files, $ci);
    }

    public function files(): Fingerprints
    {
        return $this->files;
    }

    public function ci(): CiDefinition
    {
        return $this->ci;
    }
}
