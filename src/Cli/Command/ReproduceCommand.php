<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Reproduced;
use NightWorksIO\MutationGate\Cli\Flow\Reproducing;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Report\ReproductionText;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `reproduce <id>`: one recorded mutant run again on its own, printed with
 * what the runner printed. It exits 0 where the run found what the ledger
 * recorded, 1 where it found something else, and 2 where there is no record
 * to run, the run no longer makes the mutant, or the runner cannot judge.
 */
final readonly class ReproduceCommand
{
    private const string DIFFERS = <<<'SAID'
        The run found it %s where the ledger recorded it %s: it may be flaky, or its code or tests changed since.
        SAID;

    private const string UNMADE = '%s Its code, or its mutators, changed since it was recorded.';

    public static function command(Composition $composition): Command
    {
        return new Command('reproduce')
            ->setDescription('Run one mutant again and show why it survives')
            ->addArgument('id', InputArgument::REQUIRED, 'The mutant\'s id, or its first six or more characters')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);
                $id = $input->getArgument('id');
                $sought = IdPrefix::parse(is_string($id) ? $id : '');

                if (! $composed instanceof Composed || $sought instanceof CannotJudge) {
                    return Failed::because($output, $composed instanceof Composed ? $sought : $composed);
                }

                $reproduced = new Reproducing($composed->adapters, $composed->settings)->reproduce($sought);

                return $reproduced instanceof Reproduced
                    ? self::shown($output, $reproduced)
                    : Failed::unfound($output, $reproduced);
            });
    }

    private static function shown(OutputInterface $output, Reproduced $reproduced): int
    {
        $output->writeln(
            ReproductionText::of($reproduced->recorded, $reproduced->judgedBy, $reproduced->now),
            OutputInterface::OUTPUT_RAW,
        );

        $recorded = $reproduced->recorded->mutant()->status();
        $now = $reproduced->now->mutant();

        if (! $now instanceof Mutant) {
            return Failed::because($output, CannotJudge::because(sprintf(self::UNMADE, $now->why()->text())));
        }

        if ($now->status() === $recorded) {
            return ExitCode::Passed->value;
        }

        $output->writeln(sprintf(self::DIFFERS, $now->status()->value, $recorded->value), OutputInterface::OUTPUT_RAW);

        return ExitCode::Failed->value;
    }
}
