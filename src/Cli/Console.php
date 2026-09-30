<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli;

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
        'init' => 'Write a config holding what zero-config found',
        'config:show' => 'Print the effective config',
        'config:schema' => 'Print the JSON Schema of the config',
    ];

    /** @param string $vendor the Composer vendor directory the gate was installed into */
    public static function application(string $vendor): Application
    {
        $application = new Application(self::NAME);
        $application->setAutoExit(boolean: false);
        $application->getDefinition()->addOption(new InputOption(
            'no-extensions',
            mode: InputOption::VALUE_NONE,
            description: 'Load this package\'s own extension and no other',
        ));

        foreach (self::COMMANDS as $name => $description) {
            $application->addCommand(NotBuilt::command($name, $description));
        }

        $application->addCommand(PestPatch::command($vendor));

        $application->setDefaultCommand(self::DEFAULT);

        return $application;
    }
}
