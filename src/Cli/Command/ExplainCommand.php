<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Explaining;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Proof\Ambiguous;
use NightWorksIO\MutationGate\Core\Proof\NoRecord;
use NightWorksIO\MutationGate\Core\Report\ExplanationJson;
use NightWorksIO\MutationGate\Core\Report\Explanations;
use NightWorksIO\MutationGate\Core\Report\ExplanationText;
use NightWorksIO\MutationGate\Core\Report\Form;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `explain <id>`: one mutant, or a cluster of survivors, explained from the
 * ledgers and the last run, running nothing, as text or with
 * `--format=json` as its public JSON (ADR-0014, decisions 12 to 15). It
 * exits 0 once it has explained, and 2 where no record holds the mutant, a
 * prefix names several, or the id is not one. A cluster's id is twelve hex
 * characters too, so the flow tells the two apart by what the last run holds.
 */
final readonly class ExplainCommand
{
    private const string NAME = 'explain';

    public static function command(Composition $composition): Command
    {
        return FormOption::on(new Command(self::NAME))
            ->setDescription('Explain one mutant or cluster from the ledgers and the last run, running nothing')
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'The mutant\'s id, or its first six or more characters; or a cluster\'s id',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);
                $id = $input->getArgument('id');
                $sought = IdPrefix::parse(is_string($id) ? $id : '');
                $form = FormOption::asked($input, self::NAME);

                return match (true) {
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    $sought instanceof CannotJudge => Failed::because($output, $sought),
                    $form instanceof CannotJudge => Failed::because($output, $form),
                    default => self::explained($output, new Explaining($composed)->explain($sought), $form),
                };
            });
    }

    private static function explained(
        OutputInterface $output,
        Explanations|NoRecord|Ambiguous|CannotJudge $explained,
        Form $form,
    ): int {
        if (! $explained instanceof Explanations) {
            return Failed::unfound($output, $explained);
        }

        $output->writeln(
            $form === Form::Json ? ExplanationJson::of($explained) : ExplanationText::of($explained),
            OutputInterface::OUTPUT_RAW,
        );

        return ExitCode::Passed->value;
    }
}
