<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Selected;
use NightWorksIO\MutationGate\Cli\Flow\Selecting;
use NightWorksIO\MutationGate\Cli\Flow\Workspace;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\Package;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Report\AffectedFormat;
use NightWorksIO\MutationGate\Core\Report\AffectedJson;
use NightWorksIO\MutationGate\Core\Report\AffectedText;
use NightWorksIO\MutationGate\Core\Runner\Identity;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitOption;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `affected`: the tests a change can make fail, printed for a plain test run
 * (ADR-0020, decisions 1 to 4), running nothing. The list goes to standard
 * output in the format `--format` names, and the reasons to standard error,
 * but in JSON, which holds them. It exits 0 whenever it answered, and 2 where
 * git cannot say what changed or the format cannot list the tests.
 */
final readonly class AffectedCommand
{
    private const string FORMAT_WHAT = '--format takes files, files0, ids or json, not "%s".';

    private const string PEST_IDS = 'Pest\'s test ids are not ones PHPUnit\'s filter takes: give --format=files.';

    private const string OLD_IDS
        = '--format=ids needs PHPUnit %s or later, and the runner drives %s: give --format=files.';

    private const string NO_PHPUNIT
        = '--format=ids needs PHPUnit %s or later, and the runner drives no PHPUnit: give --format=files.';

    public static function command(Composition $composition): Command
    {
        return new Command('affected')
            ->setDescription('List the tests a change can make fail, for a plain test run')
            ->addOption(
                FlowOptions::CHANGED_SINCE,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Also the change since this ref, or since the last commit that passed',
            )
            ->addOption(
                FlowOptions::COVERAGE,
                mode: InputOption::VALUE_REQUIRED,
                description: 'Read the coverage map an earlier job left here, instead of .mutation-gate/coverage',
            )
            ->addOption(
                FormOption::FORMAT,
                mode: InputOption::VALUE_REQUIRED,
                description: 'files, files0, ids or json',
                default: AffectedFormat::Files->value,
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $asked = $input->getOption(FormOption::FORMAT);
                $written = is_string($asked) ? $asked : '';
                $format = AffectedFormat::tryFrom($written);

                if (! $format instanceof AffectedFormat) {
                    return Failed::because($output, CannotJudge::because(sprintf(self::FORMAT_WHAT, $written)));
                }

                $composed = $composition->compose($input);
                $refused = $composed instanceof Composed ? self::refusing($composed, $format) : $composed;

                return $refused instanceof Composed
                    ? self::selected($composed, $input, $output, $format)
                    : Failed::because($output, $refused);
            });
    }

    private static function selected(
        Composed $composed,
        InputInterface $input,
        OutputInterface $output,
        AffectedFormat $format,
    ): int {
        $since = $input->getOption(FlowOptions::CHANGED_SINCE);
        $selected = new Selecting($composed->adapters, $composed->settings)->selected(
            is_string($since) ? $since : '',
            FlowOptions::path($input, FlowOptions::COVERAGE, Workspace::coverage()),
        );
        $printed = $selected instanceof Selected ? self::printed($selected, $format) : $selected;

        if ($printed instanceof CannotJudge) {
            return Failed::because($output, $printed);
        }

        if ($format !== AffectedFormat::Json) {
            $aside = Aside::of($output);

            foreach (AffectedText::reasons($selected->tests) as $line) {
                $aside->writeln($line, OutputInterface::OUTPUT_RAW);
            }
        }

        $output->write($printed, options: OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }

    private static function printed(Selected $selected, AffectedFormat $format): string|CannotJudge
    {
        return match ($format) {
            AffectedFormat::Files, AffectedFormat::Files0 => AffectedText::files($selected->tests, $format),
            AffectedFormat::Ids => AffectedText::ids($selected->tests),
            AffectedFormat::Json => AffectedJson::of($selected->tests, $selected->base, $selected->map),
        };
    }

    /**
     * The composition, where the runner's test ids are ones PHPUnit's filter
     * takes or the format lists no ids; or why `--format=ids` cannot be given.
     */
    private static function refusing(Composed $composed, AffectedFormat $format): Composed|CannotJudge
    {
        if ($format !== AffectedFormat::Ids) {
            return $composed;
        }

        $identity = $composed->adapters->runner->identity($composed->adapters->withheld);

        return $identity instanceof Identity ? self::takingIds($composed, $identity) : $identity;
    }

    private static function takingIds(Composed $composed, Identity $identity): Composed|CannotJudge
    {
        $phpunit = NotGiven::value();

        foreach ($identity->versions() as $version) {
            if ($version->package() === Package::Pest->value) {
                return CannotJudge::because(self::PEST_IDS);
            }

            $phpunit = $version->package() === Package::PhpUnit->value ? $version : $phpunit;
        }

        return match (true) {
            $phpunit instanceof NotGiven => CannotJudge::because(sprintf(self::NO_PHPUNIT, PhpUnitOption::IDS_SINCE)),
            ! PhpUnitOption::selectsByIdsIn($phpunit) => CannotJudge::because(
                sprintf(self::OLD_IDS, PhpUnitOption::IDS_SINCE, $phpunit->release()),
            ),
            default => $composed,
        };
    }
}
