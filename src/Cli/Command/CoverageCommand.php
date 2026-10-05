<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\KeptCoverage;
use NightWorksIO\MutationGate\Cli\Flow\Measuring;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate coverage --into=<dir>`: runs the suite under coverage and
 * writes the gate's own map, `<dir>/map.json.gz`, which `plan --coverage=<dir>`
 * reads in a later job. The runner's own map, which may be code, never
 * leaves this job.
 */
final readonly class CoverageCommand
{
    private const string INTO = 'into';

    public static function command(Composition $composition): Command
    {
        return new Command('coverage')
            ->setDescription('Run the suite under coverage and write the map a later plan reads')
            ->addOption(
                self::INTO,
                mode: InputOption::VALUE_REQUIRED,
                description: 'The directory to write map.json.gz into',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);

                return $composed instanceof Composed
                    ? self::written($composed, FlowOptions::path($input, self::INTO, Workspace::coverage()), $output)
                    : Failed::because($output, $composed);
            });
    }

    /**
     * The suite's map, measured against the default branch's kept map where
     * it may be, written into a directory with each test file's entry key;
     * or why it cannot be.
     */
    private static function written(Composed $composed, Path $into, OutputInterface $output): int
    {
        $adapters = $composed->adapters;
        $inventory = Inventory::of($adapters, $composed->settings);
        $kept = new KeptCoverage($adapters, $composed->settings, $composed->setup);
        $measured = $kept->forRun($inventory, KeptCoverage::built(), ownMap: false);
        $request = $measured->request();
        $map = $adapters->runner->coverage(
            $request instanceof CoverageRun ? $request->withholding($adapters->withheld) : $request,
        );
        $keys = $map instanceof CoverageMap && $inventory instanceof Inventory
            ? $kept->keysOf($inventory, $map)
            : NotGiven::value();
        $file = CoverageMapFile::in($into);
        $at = Measuring::now($adapters);
        $written = $map instanceof CoverageMap
            ? $adapters->project->write($file, Contents::of(CoverageMapFile::encode($map, $at, $keys)))
            : $map;

        if ($written instanceof CannotJudge) {
            return Failed::because($output, $written);
        }

        $said = $measured->said();

        if (is_string($said)) {
            Aside::of($output)->writeln($said, OutputInterface::OUTPUT_RAW);
        }

        $output->writeln($written->said(), OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }
}
