<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Explaining;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cluster\ClusterId;
use NightWorksIO\MutationGate\Core\GateCommand;
use NightWorksIO\MutationGate\Core\Proof\Ambiguous;
use NightWorksIO\MutationGate\Core\Proof\NoRecord;
use NightWorksIO\MutationGate\Core\Report\ExplanationJson;
use NightWorksIO\MutationGate\Core\Report\Explanations;
use NightWorksIO\MutationGate\Core\Report\ExplanationText;
use NightWorksIO\MutationGate\Core\Report\Form;
use NightWorksIO\MutationGate\Core\Verb;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `explain <id>`: one mutant, or a cluster of survivors, explained from the
 * ledgers and the last run, running nothing, as text or with
 * `--format=json` as its public JSON (ADR-0014). It
 * exits 0 once it has explained, and 2 where no record holds the mutant, a
 * prefix names several, or the id is not one. A cluster's id is twelve hex
 * characters too, so the flow tells the two apart by what the last run holds.
 */
final readonly class ExplainCommand
{
    private const string NAME = Verb::Explain->value;

    public static function command(Composition $composition): Command
    {
        return FormOption::on(new Command(self::NAME))
            ->setDescription('Explain one mutant or cluster from the ledgers and the last run, running nothing')
            ->addArgument(
                Argument::Id->value,
                InputArgument::REQUIRED,
                'The mutant\'s id, or its first six or more characters; or a cluster\'s id',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);
                $sought = ClusterId::orMutant(CommandText::argument($input, Argument::Id));
                $form = FormOption::asked($input, self::NAME);

                return match (true) {
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    $sought instanceof CannotJudge => Failed::because($output, $sought),
                    $form instanceof CannotJudge => Failed::because($output, $form),
                    default => self::explained(
                        $output,
                        new Explaining($composed)->explain($sought),
                        $form,
                        $composed->setup->command,
                    ),
                };
            });
    }

    private static function explained(
        OutputInterface $output,
        Explanations|NoRecord|Ambiguous|CannotJudge $explained,
        Form $form,
        GateCommand $gate,
    ): int {
        if (! $explained instanceof Explanations) {
            return Failed::unfound($output, $explained, $gate);
        }

        $output->writeln(
            $form === Form::Json ? ExplanationJson::of($explained, $gate) : ExplanationText::of($explained, $gate),
            OutputInterface::OUTPUT_RAW,
        );

        return ExitCode::Passed->value;
    }
}
