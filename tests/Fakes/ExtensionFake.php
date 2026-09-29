<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Fakes;

use NightWorksIO\MutationGate\Core\Config\Name;
use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Port\Runner;

/** An extension a package can name, which offers the fake runner as `fake`. */
final readonly class ExtensionFake implements Extension
{
    public function extend(Extensions $extensions): Extensions
    {
        return $extensions->withRunner(Name::of('fake'), static fn(): Runner => RunnerFake::ofTheFixture());
    }
}
