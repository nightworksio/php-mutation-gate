<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function implode;

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
use NightWorksIO\MutationGate\Core\Config\KeyedContexts;
use NightWorksIO\MutationGate\Core\Config\Layer;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\Proof\Ledger;
use NightWorksIO\MutationGate\Core\Verb;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `config:suggest [--ledger=<file>]`: the changes to the config the ledger
 * gives evidence for, each with its evidence, then the config file with them
 * laid over it as a unified diff in its own format (ADR-0076). It proposes
 * and never applies: it writes nothing, and exits 0 whatever it suggests,
 * and 2 where the config or the ledger cannot be read. Where
 * `suggest.enabled` is `false` it says suggestions are off and reads no
 * ledger (ADR-0104).
 *
 * @internal
 */
final readonly class ConfigSuggest
{
    /** What it prints where `suggest.enabled` is `false`. */
    public const string OFF = "Suggestions are off: suggest.enabled is false, so nothing is proposed.\n";

    /** What it warns of where the `pr` and `main` blocks resolve a keyed setting differently. */
    private const string KEYED = 'Warning: a pull request and the default branch resolve %s differently, so a pull'
        . ' request takes none of the default branch\'s proofs of what it reaches. Set them alike for pr and main.';

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
                $composed = $composition->compose($input);
                $suggests = $composed instanceof Composed
                    && $composed->settings->effective()->reports()->suggest()->enabled();

                return match (true) {
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    ! $suggests => self::printed($output, self::OFF),
                    default => self::suggested($composed, $effective, $formats, $project, $input, $output),
                };
            });
    }

    /** The changes the ledger gives evidence for, as a diff of the config file; or why they cannot be told. */
    private static function suggested(
        Composed $composed,
        Effective $effective,
        Formats $formats,
        string $project,
        InputInterface $input,
        OutputInterface $output,
    ): int {
        $given = CommandLine::from($input);
        $ledger = TalliedLedger::read($composed, LedgerOption::given($input));
        $written = $effective->written($given);
        $located = ConfigLocation::in($project, $given->config);

        $keyed = $written instanceof Layer ? KeyedContexts::of($written)->keys() : [];

        if ($keyed !== []) {
            Aside::of($output)->writeln(sprintf(self::KEYED, implode(Fit::COMMA, $keyed)));
        }

        return match (true) {
            ! $ledger instanceof Ledger => Failed::because($output, $ledger),
            $written instanceof Invalid, $written instanceof CannotJudge => Failed::because($output, $written),
            $located instanceof CannotJudge => Failed::because($output, $located),
            default => self::printed(
                $output,
                new SuggestedConfig($composed, $formats, $project)->said($ledger, $written, $located),
            ),
        };
    }

    private static function printed(OutputInterface $output, string $said): int
    {
        $output->write($said, options: OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }
}
