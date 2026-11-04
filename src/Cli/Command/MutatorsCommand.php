<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\TalliedLedger;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Report\Form;
use NightWorksIO\MutationGate\Core\Report\MutatorReport;
use NightWorksIO\MutationGate\Core\Verb;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutators [--format=text|json] [--ledger=<file>]`: what each mutator costs
 * and finds, from the tally of the default branch's ledger, or of the file
 * `--ledger` names, running and writing nothing (ADR-0076). It exits 0 once
 * it printed the report, and 2 where the ledger cannot be read.
 *
 * @internal
 */
final readonly class MutatorsCommand
{
    private const string NAME = Verb::Mutators->value;

    public static function command(Composition $composition): Command
    {
        return LedgerOption::on(FormOption::on(new Command(self::NAME)))
            ->setDescription('Print what each mutator costs and finds, from the ledger, running nothing')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);
                $form = FormOption::asked($input, self::NAME);
                $ledger = $composed instanceof Composed
                    ? TalliedLedger::read($composed, LedgerOption::given($input))
                    : $composed;

                if ($form instanceof CannotJudge) {
                    return Failed::because($output, $form);
                }

                if (! $ledger instanceof Ledger) {
                    return Failed::because($output, $ledger);
                }

                $tallies = $ledger->learned()->tallies();
                $report = $form === Form::Json
                    ? sprintf("%s\n", MutatorReport::json($tallies))
                    : MutatorReport::text($tallies);
                $output->write($report, options: OutputInterface::OUTPUT_RAW);

                return ExitCode::Passed->value;
            });
    }
}
