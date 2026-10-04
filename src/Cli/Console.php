<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function class_exists;
use function getenv;
use function ini_get;
use function ini_parse_quantity;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Filesystem\Waiting;
use NightWorksIO\MutationGate\Adapter\Runtime\ChildMemory;
use NightWorksIO\MutationGate\Adapter\Runtime\PhpProbe;
use NightWorksIO\MutationGate\Cli\Command\BaselineCommand;
use NightWorksIO\MutationGate\Cli\Command\ConfigSchema;
use NightWorksIO\MutationGate\Cli\Command\ConfigShow;
use NightWorksIO\MutationGate\Cli\Command\CoverageCommand;
use NightWorksIO\MutationGate\Cli\Command\Doctor;
use NightWorksIO\MutationGate\Cli\Command\ExplainCommand;
use NightWorksIO\MutationGate\Cli\Command\HookCommand;
use NightWorksIO\MutationGate\Cli\Command\Init;
use NightWorksIO\MutationGate\Cli\Command\PlanCommand;
use NightWorksIO\MutationGate\Cli\Command\PreCommitCommand;
use NightWorksIO\MutationGate\Cli\Command\PrePushCommand;
use NightWorksIO\MutationGate\Cli\Command\ReproduceCommand;
use NightWorksIO\MutationGate\Cli\Command\RunCommand;
use NightWorksIO\MutationGate\Cli\Command\StubCommand;
use NightWorksIO\MutationGate\Cli\Command\TestsCommand;
use NightWorksIO\MutationGate\Cli\Command\TriageCommand;
use NightWorksIO\MutationGate\Cli\Command\VerdictCommand;
use NightWorksIO\MutationGate\Cli\Command\WatchCommand;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\Doctor\Measure;
use NightWorksIO\MutationGate\Cli\Doctor\Observed;
use NightWorksIO\MutationGate\Cli\Doctor\Online;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Core\Ci\GatePin;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Composer\Installed;
use NightWorksIO\MutationGate\Core\Doctor\DoctorRun;
use NightWorksIO\MutationGate\Core\Troubleshooting\Guide;
use NightWorksIO\MutationGate\Core\Watch\Poll;
use NightWorksIO\MutationGate\Extension\Extensions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\HttpClient\HttpClient;

/** The command line: every command the README lists, with `run` when none is named. */
final readonly class Console
{
    private const string NAME = 'mutation-gate';

    private const string DEFAULT = 'run';

    /**
     * The command line of a project, with the extensions found for it, the vendor directory it was installed
     * into, the clock it reads the time from and the environment it was started in.
     */
    public static function application(
        Extensions $extensions,
        string $project,
        string $vendor,
        ClockInterface $clock,
        Variables $environment,
    ): Application {
        $application = new Application(self::NAME, InstalledGate::version()->spelt());
        $application->setAutoExit(boolean: false);
        $definition = $application->getDefinition();
        $definition->addOption(new InputOption(
            'no-extensions',
            mode: InputOption::VALUE_NONE,
            description: 'Load the extensions of this package and its first-party plugins, and no third-party code',
        ));
        $definition->addOption(new InputOption(
            'config',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Read this config file instead of looking for one',
        ));
        $definition->addOption(new InputOption('runner', mode: InputOption::VALUE_REQUIRED, description: 'Set runner'));
        $definition->addOption(new InputOption(
            'report',
            mode: InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            description: 'Add a file report to reports, written <name>:<path>',
        ));
        $definition->addOption(new InputOption(
            'budget',
            mode: InputOption::VALUE_REQUIRED,
            description: 'Set budget, the time a run may take: 90s, 15m, 1h30m',
        ));
        $definition->addOption(new InputOption(
            'ci',
            mode: InputOption::VALUE_OPTIONAL,
            description: 'Set ci.plan; with init, write the CI definition, for the detected CI where none is named',
            default: false,
        ));

        $application->addCommand(PestPatch::command(ComposerVendor::on($project)));
        $application->addCommand(HookCommand::command($project));

        $detected = new Detected(Directory::at($project), Directory::at($vendor));
        $now = $clock->now();
        $effective = new Effective($project, $extensions, $detected, $now);
        $composition = new Composition(
            $effective,
            $extensions,
            $project,
            $vendor,
            $clock,
            new ChildMemory(),
            $environment,
        );
        $application->addCommand(RunCommand::command($composition));
        $application->addCommand(PlanCommand::command($composition));
        $application->addCommand(VerdictCommand::command($composition));
        $application->addCommand(BaselineCommand::command($composition));
        $application->addCommand(CoverageCommand::command($composition));
        $application->addCommand(PreCommitCommand::command($composition));
        $application->addCommand(PrePushCommand::command($composition));
        $application->addCommand(WatchCommand::command($composition, Waiting::every(Poll::interval())));
        $application->addCommand(ReproduceCommand::command($composition));
        $application->addCommand(ExplainCommand::command($composition));
        $application->addCommand(TestsCommand::command($composition));
        $application->addCommand(StubCommand::command($composition));
        $application->addCommand(TriageCommand::command($composition));
        $formats = new Formats(class_exists(...));
        $installed = $detected->installed();
        $gate = $installed instanceof Installed ? GatePin::in($installed) : GatePin::unknown();
        $application->addCommand(Init::command($project, $extensions, $effective, $formats, $now, $gate));
        $application->addCommand(Init::import($project, $extensions, $effective, $formats, $now, $gate));
        $application->addCommand(ConfigShow::command($effective, $formats));
        $application->addCommand(ConfigSchema::command());
        $probe = PhpProbe::of(PHP_BINARY, getenv());
        $application->addCommand(Doctor::command(
            new Observed(
                $project,
                $extensions,
                $effective,
                $detected,
                $probe,
                DoctorRun::of($now, ini_parse_quantity(ini_get('memory_limit'))),
            ),
            new Measure($composition),
            new Online($project, $environment, HttpClient::create()),
            $installed instanceof Installed ? Guide::installedIn($installed) : Guide::unreleased(),
        ));
        $application->setDefaultCommand(self::DEFAULT);

        return $application;
    }
}
