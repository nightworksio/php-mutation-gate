<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\PeakMemory;
use NightWorksIO\MutationGate\Core\Runner\Version;
use Psr\Clock\ClockInterface;

/**
 * What a flow knows of how the gate was set up: the config file it read,
 * the gate's own version, the digest of what Composer installed, the clock
 * it reads the time from, and the most memory the processes it started have
 * held.
 */
final readonly class Setup
{
    public function __construct(
        public Path|Absent $configFile,
        public Version $gate,
        public Digest $installed,
        public ClockInterface $clock,
        public PeakMemory $memory,
    ) {
    }
}
