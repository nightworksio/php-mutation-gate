<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Init;

use NightWorksIO\MutationGate\Cli\Command\CiRequest;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\InfectionFile;
use NightWorksIO\MutationGate\Core\NotGiven;

/**
 * What `init`'s first questions settle, before the settings are read: the
 * command line with the runner a person chose, the Infection config to
 * import, and the CI to write a definition for.
 */
final readonly class InitAnswers
{
    private function __construct(
        public CommandLine $given,
        public InfectionFile|NotGiven $from,
        public CiRequest|NotGiven $ci,
    ) {
    }

    public static function of(CommandLine $given, InfectionFile|NotGiven $from, CiRequest|NotGiven $ci): self
    {
        return new self($given, $from, $ci);
    }
}
