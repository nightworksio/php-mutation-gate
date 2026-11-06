<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use NightWorksIO\MutationGate\Cli\CommandLine;
use NightWorksIO\MutationGate\Cli\Config\ConfigLocation;
use NightWorksIO\MutationGate\Cli\Config\Effective;
use NightWorksIO\MutationGate\Cli\Config\Formats;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\SuggestedConfig;
use NightWorksIO\MutationGate\Cli\Flow\TalliedLedger;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Invalid;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Verb;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `config:suggest [--ledger=<file>]`: the changes to the config the ledger
 * gives evidence for, each with its evidence, then the config file with them
 * laid over it as a unified diff in its own format (ADR-0076). It proposes
 * and never applies: it writes nothing, and exits 0 whatever it suggests,
 * and 2 where the config or the ledger cannot be read.
 *
 * @internal
 */
final readonly class ConfigSuggest
{
    public static function command(
        Composition $composition,
        Effective $effective,
        Formats $formats,
        string $project,
    ): Command {
        return LedgerOption::on(new Command(Verb::ConfigSuggest->value))
            ->setDescription('Print the config changes the ledger gives evidence for, as a diff, writing nothing')
            ->setCode(static function (InputInterface $input, OutputInterface $output) use (
                $composition,
                $effective,
                $formats,
                $project,
            ): int {
                $given = CommandLine::from($input);
                $composed = $composition->compose($input);
                $ledger = $composed instanceof Composed
                    ? TalliedLedger::read($composed, LedgerOption::given($input))
                    : $composed;
                $written = $effective->written($given);
                $located = ConfigLocation::in($project, $given->config);

                return match (true) {
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    ! $ledger instanceof Ledger => Failed::because($output, $ledger),
                    $written instanceof Invalid, $written instanceof CannotJudge => Failed::because($output, $written),
                    $located instanceof CannotJudge => Failed::because($output, $located),
                    default => self::printed(
                        $output,
                        new SuggestedConfig($composed, $formats, $project)->said($ledger, $written, $located),
                    ),
                };
            });
    }

    private static function printed(OutputInterface $output, string $said): int
    {
        $output->write($said, options: OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }
}
