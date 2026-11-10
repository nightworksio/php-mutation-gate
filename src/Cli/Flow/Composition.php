<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\Chosen;
use NightWorksIO\MutationGate\Cli\Config\ConfigLocation;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\NoConfigFile;
use NightWorksIO\MutationGate\Cli\InstalledGate;
use NightWorksIO\MutationGate\Cli\Registry\Lookup;
use NightWorksIO\MutationGate\Cli\Registry\RuledMutators;
use NightWorksIO\MutationGate\Cli\StartedGate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Config\Context;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Proof\Key\InstalledPackages;
use NightWorksIO\MutationGate\Core\Runner\PeakMemory;
use NightWorksIO\MutationGate\Extension\Extensions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;

/**
 * What a command's flow runs with, composed from its options: the settings
 * every layer of the config comes to, the adapters they choose from the
 * registry and the config's own extensions, what the gate knows of its own
 * setup, and a reporter for each entry of `reports`.
 *
 * @internal
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
        private PeakMemory $memory,
        private Variables $environment,
    ) {
    }

    private function setup(Path|NoConfigFile $configFile, CommandLine $given): Setup
    {
        $installed = Directory::at($this->vendor)->read(Installed::fileIn(Path::root()));
        $file = $configFile instanceof Path ? $configFile->relativeTo(Path::of($this->project)) : Absent::setting();

        return new Setup(
            $file,
            InstalledGate::version(),
            InstalledPackages::digestOf($installed),
            $this->clock,
            $this->memory,
            $file instanceof Path
                ? DecidingConfig::read($this->effective, $given, $this->project, $file)
                : DecidingConfig::unread(),
            StartedGate::in($this->project),
        );
    }

    public function compose(InputInterface $input): Composed|Invalid|CannotJudge
    {
        return $this->given(CommandLine::from($input));
    }

    /** What a command a developer runs while working composes: a run in `local` where `--context` names none. */
    public function composeLocally(InputInterface $input): Composed|Invalid|CannotJudge
    {
        return $this->given(CommandLine::from($input)->defaultingTo(Context::Local));
    }

    /**
     * What a round of `watch` composes: as a command a developer runs, and,
     * where `local.quick` makes its rounds quick, with what a quick round
     * lays over the config (ADR-0146).
     */
    public function composeForWatch(InputInterface $input): Composed|Invalid|CannotJudge
    {
        $given = CommandLine::from($input)->defaultingTo(Context::Local);
        $composed = $this->given($given);

        return $composed instanceof Composed && $composed->settings->local()->quick()
            ? $this->given($given->quickly())
            : $composed;
    }

    private function given(CommandLine $given): Composed|Invalid|CannotJudge
    {
        $settings = $this->effective->settings($given);

        if ($settings instanceof Invalid || $settings instanceof CannotJudge) {
            return $settings;
        }

        $registry = new Chosen($this->extensions)->given($given, $settings->extensions());
        $configFile = ConfigLocation::in($this->project, $given->config);

        return match (true) {
            $registry instanceof CannotJudge => $registry,
            $configFile instanceof CannotJudge => $configFile,
            default => $this->composed($settings, $registry, $configFile, $given),
        };
    }

    private function composed(
        Settings $settings,
        Extensions $registry,
        Path|NoConfigFile $configFile,
        CommandLine $given,
    ): Composed|Invalid|CannotJudge {
        $project = Directory::at($this->project);
        $detected = new Detected($project, Directory::at($this->vendor));
        $wiring = new Wiring($registry, $this->environment, $detected);
        $adapters = $wiring->adapters($settings, $project);
        $reporting = new Reporting(new Chosen($registry), $this->environment);
        $reporters = $reporting->reporters($settings, RunOn::detached(CannotTell::because(self::UNPLANNED)));
        $ruled = RuledMutators::checked(Lookup::in($registry), $settings);

        return match (true) {
            $adapters instanceof Invalid, $adapters instanceof CannotJudge => $adapters,
            $ruled instanceof Invalid, $ruled instanceof CannotJudge => $ruled,
            $reporters instanceof Invalid, $reporters instanceof CannotJudge => $reporters,
            default => new Composed(
                $settings,
                $adapters->readingConfig($this->effective->reads($given)),
                $this->setup($configFile, $given),
                $reporting,
                Ruling::by($wiring->adapters(...)),
            ),
        };
    }
}
