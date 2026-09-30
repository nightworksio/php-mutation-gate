<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\File\Paths;

/** How the project's `composer.json` installs its packages, as far as a check asks it. */
final readonly class ComposerSetup
{
    private function __construct(private Paths $mirrored)
    {
    }

    /** @param Paths $mirrored every directory a path repository copies into the vendor directory */
    public static function of(Paths $mirrored): self
    {
        return new self($mirrored);
    }

    /** Every directory a path repository copies into the vendor directory, `symlink: false`, rather than links to. */
    public function mirrored(): Paths
    {
        return $this->mirrored;
    }
}
