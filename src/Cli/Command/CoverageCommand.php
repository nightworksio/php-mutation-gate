<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\CoverageMeasured;
use NightWorksIO\MutationGate\Cli\Flow\Inventory;
use NightWorksIO\MutationGate\Cli\Flow\KeptCoverage;
use NightWorksIO\MutationGate\Cli\Flow\Measuring;
use NightWorksIO\MutationGate\Cli\Flow\SuiteCoverage;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\CoverageRan;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate coverage --into=<dir>`: runs the suite under coverage and
 * writes the gate's own map, `<dir>/map.json.gz`, which `plan --coverage=<dir>`
 * reads in a later job. With `--from=<dir>` it runs nothing: it reads the
 * reports the project's own run of its suite under coverage left in that
 * directory, in this job, as a test job's run does, so the suite runs once
 * for both. The runner's own map, which may be code, never leaves this job.
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
            ->addOption(
                FlowOptions::FROM,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Read the reports this job\'s own coverage run left in this directory instead of running',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);

                return $composed instanceof Composed
                    ? self::written(
                        $composed,
                        FlowOptions::path($input, self::INTO, Workspace::coverage()),
                        FlowOptions::coverageRan($input),
                        $output,
                    )
                    : Failed::because($output, $composed);
            });
    }

    /** A map the project's own run left, with only what the listed suites may judge in it; or why there is none. */
    private static function admitted(
        CoverageMap|CannotJudge $map,
        SuiteCoverage|NotGiven $suites,
    ): CoverageMap|CannotJudge {
        return $map instanceof CoverageMap && $suites instanceof SuiteCoverage ? $suites->admitted($map) : $map;
    }

    /**
     * The suite's map, measured against the default branch's kept map where
     * it may be, or read from the reports a run in this job left, written
     * into a directory with each test file's entry key; or why it cannot be.
     */
    private static function written(
        Composed $composed,
        Path $into,
        CoverageRan|NotGiven $ran,
        OutputInterface $output,
    ): int {
        $adapters = $composed->adapters;
        $inventory = Inventory::of($adapters, $composed->settings);
        $suites = match (true) {
            $inventory instanceof Inventory => SuiteCoverage::of($adapters, $inventory),
            $ran instanceof CoverageRan => $inventory,
            default => NotGiven::value(),
        };

        if ($suites instanceof CannotJudge) {
            return Failed::because($output, $suites);
        }

        $kept = new KeptCoverage($adapters, $composed->settings, $composed->setup);
        $entries = $kept->entries($inventory);
        $measured = $ran instanceof CoverageRan
            ? $ran
            : $kept->forRun($inventory, $entries, KeptCoverage::built(), ownMap: false, suites: $suites);
        $map = $measured instanceof CoverageMeasured
            ? $kept->mapOf($measured, $suites)
            : self::admitted($adapters->runner->coverage($measured), $suites);
        $keys = $map instanceof CoverageMap ? KeptCoverage::keysOf($entries, $map) : NotGiven::value();
        $file = CoverageMapFile::in($into);
        $at = Measuring::now($adapters);
        $written = $map instanceof CoverageMap
            ? $adapters->project->write($file, Contents::of(CoverageMapFile::encode($map, $at, $keys)))
            : $map;

        if ($written instanceof CannotJudge) {
            return Failed::because($output, $written);
        }

        $said = $measured instanceof CoverageMeasured ? $measured->said() : NotGiven::value();

        if (is_string($said)) {
            Aside::of($output)->writeln($said, OutputInterface::OUTPUT_RAW);
        }

        $output->writeln($written->said(), OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }
}
