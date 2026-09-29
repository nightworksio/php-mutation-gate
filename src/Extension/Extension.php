<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension;

/**
 * What a package offers the gate. A package names its extension classes in
 * its `composer.json` under `extra.mutation-gate.extensions`, and each is
 * constructed with no arguments.
 */
interface Extension
{
    /** The registry with this extension's adapters and presets added. */
    public function extend(Extensions $extensions): Extensions;
}
