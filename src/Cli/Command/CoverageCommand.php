<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Measuring;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
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

    private static function written(Composed $composed, Path $into, OutputInterface $output): int
    {
        $request = CoverageRun::of(WholeSuite::tests(), Workspace::coverage())
            ->withholding($composed->adapters->withheld);
        $map = $composed->adapters->runner->coverage($request);
        $file = CoverageMapFile::in($into);
        $at = Measuring::now($composed->adapters);
        $written = $map instanceof CoverageMap
            ? $composed->adapters->project->write($file, Contents::of(CoverageMapFile::encode($map, $at)))
            : $map;

        if ($written instanceof CannotJudge) {
            return Failed::because($output, $written);
        }

        $output->writeln($written->said(), OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }
}
