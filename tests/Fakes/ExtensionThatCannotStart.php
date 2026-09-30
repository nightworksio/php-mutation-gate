<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use RuntimeException;

/** An extension whose constructor fails, as one that reads a missing file of its own might. */
final readonly class ExtensionThatCannotStart implements Extension
{
    public function __construct()
    {
        throw new RuntimeException('the settings file of this extension is missing');
    }

    public function extend(Extensions $extensions): Extensions
    {
        return $extensions;
    }
}
