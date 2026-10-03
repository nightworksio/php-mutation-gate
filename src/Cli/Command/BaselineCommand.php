<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Baselines;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Measured;
use NightWorksIO\MutationGate\Cli\Flow\Raising;
use NightWorksIO\MutationGate\Core\Baseline\Baseline;
use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Score;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate baseline` shows every tree's floor beside its last measured
 * score, the one the newest result of each of its units adds up to.
 * `baseline --write` sets every improved floor, and every missing one, to
 * that score; it never lowers one.
 */
final readonly class BaselineCommand
{
    private const string UNMEASURED = <<<'SAID'
        %s: floor %s, not measured: a unit of it has no result yet. Run mutation-gate to measure it.
        SAID;

    public static function command(Composition $composition): Command
    {
        return new Command('baseline')
            ->setDescription('Show, or write, floors raised to what was measured')
            ->addOption(FlowOptions::WRITE, mode: InputOption::VALUE_NONE, description: 'Write every improved floor')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);

                if (! $composed instanceof Composed) {
                    return Failed::because($output, $composed);
                }

                $baselines = new Baselines($composed->adapters, $composed->settings->floors()->baseline());
                $committed = $baselines->committed();

                if ($committed instanceof CannotJudge) {
                    return Failed::because($output, $committed);
                }

                $measured = Measured::of(
                    $composed->adapters,
                    $composed->settings,
                    $committed,
                    $composed->setup->clock->now(),
                );

                return $measured instanceof Measured
                    ? self::said($input, $output, $baselines, $committed, $measured)
                    : Failed::because($output, $measured);
            });
    }

    private static function said(
        InputInterface $input,
        OutputInterface $output,
        Baselines $baselines,
        Baseline $committed,
        Measured $measured,
    ): int {
        $lines = $input->getOption(FlowOptions::WRITE) === true
            ? new Raising($baselines)->raise($committed, $measured->trees())
            : self::shown($committed, $measured);

        if ($lines instanceof CannotJudge) {
            return Failed::because($output, $lines);
        }

        foreach ($lines as $line) {
            $output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return ExitCode::Passed->value;
    }

    /** @return list<string> each tree's floor and its last measured score */
    private static function shown(Baseline $committed, Measured $measured): array
    {
        $lines = [];

        foreach ($measured->trees() as $tree) {
            $score = $tree->score();
            $lines[] = sprintf(
                '%s: floor %s, measured %s',
                $tree->tree()->path()->value(),
                self::floorOf($committed, $tree->tree()->path()),
                $score instanceof Score
                    ? BaselineFile::number(Floor::ofHundredths($score->hundredths()))
                    : 'nothing to mutate',
            );
        }

        foreach ($measured->unmeasured() as $tree) {
            $lines[] = sprintf(self::UNMEASURED, $tree->value(), self::floorOf($committed, $tree));
        }

        return $lines;
    }

    private static function floorOf(Baseline $baseline, Path $tree): string
    {
        $floor = $baseline->floorOf($tree);

        return $floor instanceof Floor ? BaselineFile::number($floor) : 'none';
    }
}
