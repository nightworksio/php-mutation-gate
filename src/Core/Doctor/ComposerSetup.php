<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Doctor;

use NightWorksIO\MutationGate\Core\File\Paths;

/** How the project's `composer.json` installs its packages, and what it does to them, as far as a check asks it. */
final readonly class ComposerSetup
{
    private function __construct(private Paths $mirrored, private Patched $infection, private Patched $pest)
    {
    }

    /**
     * @param Paths   $mirrored  every directory a path repository copies into the vendor directory
     * @param Patched $infection whether the installed Infection carries `infection:patch`
     * @param Patched $pest      whether the installed pest-plugin-mutate carries `pest:patch`
     */
    public static function of(
        Paths $mirrored,
        Patched $infection = Patched::NotInstalled,
        Patched $pest = Patched::NotInstalled,
    ): self {
        return new self($mirrored, $infection, $pest);
    }

    /** Whether the installed pest-plugin-mutate carries `pest:patch`. */
    public function pest(): Patched
    {
        return $this->pest;
    }

    /** Whether the installed Infection carries `infection:patch`. */
    public function infection(): Patched
    {
        return $this->infection;
    }

    /** Every directory a path repository copies into the vendor directory, `symlink: false`, rather than links to. */
    public function mirrored(): Paths
    {
        return $this->mirrored;
    }
}
