<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Config\Settings;

/** What a command runs its flow with: the settings, the adapters they chose, the setup and the reporting. */
final readonly class Composed
{
    public function __construct(
        public Settings $settings,
        public Adapters $adapters,
        public Setup $setup,
        public Reporting $reporting,
    ) {
    }
}
