<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

use function class_exists;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Cli\Command\ConfigSchema;
use NightWorksIO\MutationGate\Cli\Command\ConfigShow;
use NightWorksIO\MutationGate\Cli\Command\Init;
use NightWorksIO\MutationGate\Cli\Config\Detected;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Extension\Extensions;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;

/** The command line: every command the README lists, with `run` when none is named. */
final readonly class Console
{
    private const string NAME = 'mutation-gate';

    private const string DEFAULT = 'run';

    private const array COMMANDS = [
        'run' => 'Plan, run and judge in one process',
        'plan' => 'Work out the reach, drop proved units, cut shards and print the plan for a CI',
        'verdict' => 'Merge every shard\'s results, judge the floors, write reports and the ledger',
        'baseline' => 'Show, or write, floors raised to what was measured',
        'reproduce' => 'Run one mutant again and show why it survives',
        'triage' => 'Run a file n times and list every mutant whose result varied',
        'watch' => 'Re-judge what each save reaches',
        'pre-push' => 'Judge the commits being pushed, as CI will',
        'hook' => 'Add or remove the pre-push hook: hook install, hook uninstall',
    ];

    /**
     * The command line of a project, with the extensions found for it, the vendor directory it was installed
     * into and the instant it runs at.
     */
    public static function application(
        Extensions $extensions,
        string $project,
        string $vendor,
        DateTimeImmutable $now,
    ): Application {
        $application = new Application(self::NAME);
        $application->setAutoExit(boolean: false);
        $definition = $application->getDefinition();
        $definition->addOption(new InputOption(
            'no-extensions',
            mode: InputOption::VALUE_NONE,
            description: 'Load this package\'s own extension and no other',
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

        foreach (self::COMMANDS as $name => $description) {
            $application->addCommand(NotBuilt::command($name, $description));
        }

        $application->addCommand(PestPatch::command($vendor));

        $detected = new Detected(Directory::at($project), Directory::at($vendor));
        $effective = new Effective($project, $extensions, $detected, $now);
        $formats = new Formats(class_exists(...));
        $application->addCommand(Init::command($project, $extensions, $effective, $formats));
        $application->addCommand(ConfigShow::command($effective, $formats));
        $application->addCommand(ConfigSchema::command());
        $application->setDefaultCommand(self::DEFAULT);

        return $application;
    }
}
