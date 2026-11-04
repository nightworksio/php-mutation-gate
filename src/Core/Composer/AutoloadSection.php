<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Composer;

/**
 * A section of a `composer.json` that maps what Composer's autoloader loads.
 *
 * @internal
 */
enum AutoloadSection: string
{
    /** The package's own code. */
    case Own = 'autoload';

    /** What only its development loads, its tests and their support among it. */
    case Development = 'autoload-dev';
}
