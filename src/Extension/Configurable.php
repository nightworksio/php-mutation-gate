<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Options;

/**
 * An adapter a config can name by its class, with the options written beside
 * it. It checks its own options, and its problems are reported at their path
 * under the adapter's `with`.
 */
interface Configurable
{
    public static function fromOptions(Options $options): self|Invalid;
}
