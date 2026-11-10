<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function array_slice;
use function is_resource;

use NightWorksIO\MutationGate\Adapter\Outbreak\Outbreak;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Verb;

use function proc_close;
use function proc_open;
use function str_starts_with;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `lsp [--severity=<s>] [-- <watch option>…]`: Outbreak's language server on
 * this process's standard streams, for an editor to start (ADR-0133
 * decision 10). It runs this gate's `watch --output=events` by this PHP,
 * with every option given here but its own and the options after `--`, and
 * shows each survivor as a diagnostic at the severity given. It exits with
 * the server's code, and 2 where no Outbreak binary is in use or the server
 * did not start. `--stdio` names the only transport it speaks,
 * which editors pass.
 *
 * @internal
 */
final readonly class LspCommand
{
    private const string NAME = Verb::Lsp->value;

    private const string SEVERITY = 'severity';

    private const string WARNING = 'warning';

    private const string STDIO = 'stdio';

    private const string WATCHING = 'watching';

    private const string REST = '--';

    private const string LONG = '--';

    private const string SEVERITY_OPTION = self::LONG . self::SEVERITY;

    private const string SEVERITY_GIVEN = self::SEVERITY_OPTION . '=';

    private const string STDIO_OPTION = self::LONG . self::STDIO;

    private const string NO_BINARY = 'mutation-gate lsp needs the Outbreak binary, and none is in use: `outbreak.enabled` is off, or none is built for this machine.';

    private const string UNSTARTED = 'mutation-gate lsp could not start the Outbreak binary.';

    public static function command(Composition $composition, string $script): Command
    {
        return new Command(self::NAME)
            ->setDescription('Serve an editor the survivors watch finds, as a language server on standard input and output')
            ->addOption(
                self::SEVERITY,
                mode: InputOption::VALUE_REQUIRED,
                description: 'error, warning, information or hint: how the editor shows a survivor',
                default: self::WARNING,
            )
            ->addOption(
                self::STDIO,
                mode: InputOption::VALUE_NONE,
                description: 'Speak on standard input and output, the only way it speaks',
            )
            ->addArgument(
                self::WATCHING,
                InputArgument::IS_ARRAY,
                'The options watch runs with, after --',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition, $script): int {
                $composed = $composition->compose($input);

                if (! $composed instanceof Composed) {
                    return Failed::because($output, $composed);
                }

                $engine = $composed->adapters->maps;
                $line = $engine instanceof Outbreak ? $engine->editing(
                    $script,
                    PHP_BINARY,
                    CommandText::option($input, self::SEVERITY),
                    self::watching($input),
                ) : NotGiven::value();

                return $line instanceof NotGiven
                    ? Failed::because($output, CannotJudge::because(self::NO_BINARY))
                    : self::served($line, $output);
            });
    }

    /**
     * The options `watch` runs with: every one given here but this command's
     * own, and every one after `--`.
     *
     * @return list<string>
     */
    private static function watching(InputInterface $input): array
    {
        $tokens = $input instanceof ArgvInput ? $input->getRawTokens(strip: true) : [];
        $handed = [];
        $valueOfOwn = false;

        foreach ($tokens as $at => $token) {
            if ($token === self::REST) {
                return [...$handed, ...array_slice($tokens, $at + 1)];
            }

            $own = $valueOfOwn
                || $token === self::SEVERITY_OPTION
                || $token === self::STDIO_OPTION
                || str_starts_with($token, self::SEVERITY_GIVEN);
            $valueOfOwn = $token === self::SEVERITY_OPTION;

            if (! $own) {
                $handed[] = $token;
            }
        }

        return $handed;
    }

    /** @param list<string> $line */
    private static function served(array $line, OutputInterface $output): int
    {
        $pipes = [];
        $process = proc_open($line, [STDIN, STDOUT, STDERR], $pipes);

        return is_resource($process)
            ? proc_close($process)
            : Failed::because($output, CannotJudge::because(self::UNSTARTED));
    }
}
