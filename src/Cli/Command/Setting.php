<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Extension\Extensions;

/**
 * What `init` and `import` write a config with: the project, its extensions,
 * its effective config and the formats, and the gate's release a CI
 * definition pins.
 */
final readonly class Setting
{
    public function __construct(
        public string $project,
        public Extensions $extensions,
        public Effective $effective,
        public Formats $formats,
        public DateTimeImmutable $now,
        public GatePin $gate,
    ) {
    }
}
