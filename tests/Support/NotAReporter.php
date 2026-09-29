<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Extension\Configurable;
use NightWorksIO\MutationGate\Extension\Options;

/** A class a config can build that adapts no port. */
final readonly class NotAReporter implements Configurable
{
    public static function fromOptions(Options $options): self|Invalid
    {
        return new self();
    }
}
