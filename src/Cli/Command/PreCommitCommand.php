<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\ScoreChanging;
use NightWorksIO\MutationGate\Core\Hook\Hook;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate pre-commit`, which the pre-commit hook calls: each reached
 * tree's score change from what the ledgers hold. It runs nothing, and it
 * always exits 0, whatever it could or could not say (ADR-0015, decision 10).
 */
final readonly class PreCommitCommand
{
    public static function command(Composition $composition): Command
    {
        return new Command(Hook::PreCommit->value)
            ->setDescription('Show the score change of what is about to be committed; it runs nothing and never blocks')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);
                $text = $composed instanceof Composed
                    ? new ScoreChanging($composed->adapters, $composed->settings, $composed->setup)->text()
                    : $composed;

                if (is_string($text)) {
                    $output->writeln($text, OutputInterface::OUTPUT_RAW);
                }

                if (! is_string($text)) {
                    Failed::because($output, $text);
                }

                return ExitCode::Passed->value;
            });
    }
}
