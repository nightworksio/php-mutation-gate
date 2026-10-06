<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\File\Paths;

/** How the project's `composer.json` installs its packages, and what it does to them, as far as a check asks it. */
final readonly class ComposerSetup
{
    private function __construct(private Paths $mirrored, private InfectionPatch $infection)
    {
    }

    /**
     * @param Paths          $mirrored  every directory a path repository copies into the vendor directory
     * @param InfectionPatch $infection whether the installed Infection carries `infection:patch`
     */
    public static function of(Paths $mirrored, InfectionPatch $infection = InfectionPatch::NotInstalled): self
    {
        return new self($mirrored, $infection);
    }

    /** Whether the installed Infection carries `infection:patch`. */
    public function infection(): InfectionPatch
    {
        return $this->infection;
    }

    /** Every directory a path repository copies into the vendor directory, `symlink: false`, rather than links to. */
    public function mirrored(): Paths
    {
        return $this->mirrored;
    }
}
