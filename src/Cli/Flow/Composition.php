<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Composer\InstalledVersions;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\Config\ConfigLocation;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\NoConfigFile;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\Version;
use NightWorksIO\MutationGate\Extension\Extensions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;

/**
 * What a command's flow runs with, composed from its options: the settings
 * every layer of the config comes to, the adapters they choose from the
 * registry and the config's own extensions, what the gate knows of its own
 * setup, and a reporter for each entry of `reports`.
 */
final readonly class Composition
{
    private const string UNPLANNED = 'Every reporter is checked before the run knows what it runs on.';

    public function __construct(
        private Effective $effective,
        private Extensions $extensions,
        private string $project,
        private string $vendor,
        private ClockInterface $clock,
        private Variables $environment,
    ) {
    }

    private function setup(Path|NoConfigFile $configFile): Setup
    {
        $installed = Directory::at($this->vendor)->read(Installed::fileIn(Path::root()));

        return new Setup(
            $configFile instanceof Path ? $configFile->relativeTo(Path::of($this->project)) : Absent::setting(),
            Version::of(
                FirstParty::PACKAGE,
                InstalledVersions::getPrettyVersion(FirstParty::PACKAGE) ?? '',
                InstalledVersions::getReference(FirstParty::PACKAGE) ?? '',
            ),
            Digest::sha256Of($installed instanceof Contents ? $installed->text() : ''),
            $this->clock,
        );
    }

    public function compose(InputInterface $input): Composed|Invalid|CannotJudge
    {
        $given = CommandLine::from($input);
        $settings = $this->effective->settings($given);

        if ($settings instanceof Invalid || $settings instanceof CannotJudge) {
            return $settings;
        }

        $registry = $given->firstPartyOnly
            ? $this->extensions
            : new Chosen($this->extensions)->withExtensions($settings->extensions());
        $configFile = ConfigLocation::in($this->project, $given->config);

        return match (true) {
            $registry instanceof CannotJudge => $registry,
            $configFile instanceof CannotJudge => $configFile,
            default => $this->composed($settings, $registry, $configFile),
        };
    }

    private function composed(
        Settings $settings,
        Extensions $registry,
        Path|NoConfigFile $configFile,
    ): Composed|Invalid|CannotJudge {
        $project = Directory::at($this->project);
        $detected = new Detected($project, Directory::at($this->vendor));
        $adapters = new Wiring($registry, $this->environment, $detected)->adapters($settings, $project);
        $reporting = new Reporting(new Chosen($registry), $this->environment);
        $reporters = $reporting->reporters($settings, RunOn::detached(CannotTell::because(self::UNPLANNED)));

        return match (true) {
            $adapters instanceof Invalid, $adapters instanceof CannotJudge => $adapters,
            $reporters instanceof Invalid, $reporters instanceof CannotJudge => $reporters,
            default => new Composed($settings, $adapters, $this->setup($configFile), $reporting),
        };
    }
}
