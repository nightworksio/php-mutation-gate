<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;

/**
 * This package's own adapters and presets, registered through the same
 * discovery as any other package's, and named in this package's
 * `composer.json`.
 */
final readonly class FirstParty implements Extension
{
    /** The Composer package this extension comes from. */
    public const string PACKAGE = 'nightworksio/mutation-gate';

    public function extend(Extensions $extensions): Extensions
    {
        return $extensions;
    }
}
