<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function is_string;

use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Flow\Stubbing;
use NightWorksIO\MutationGate\Core\Assertion\AssertionStyle;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Proof\Ambiguous;
use NightWorksIO\MutationGate\Core\Proof\NoRecord;
use NightWorksIO\MutationGate\Core\Stub\Stub;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `stub <id>`: a failing test for a survivor or an uncovered mutant, or for a
 * cluster, printed, or with `--write` added to the test file it follows or
 * written as a new one, never overwriting a file (ADR-0015, decisions 1 to
 * 5). It exits 0 once it has printed or written the test, and 2 where there
 * is nothing to stub, no record holds the mutant, or the id is not one.
 */
final readonly class StubCommand
{
    private const string STYLE = 'style';

    private const string UNKNOWN_STYLE = '--style is %s; stub writes pest or phpunit.';

    public static function command(Composition $composition): Command
    {
        return new Command('stub')
            ->setDescription('Print, or with --write add, a failing test for a survivor or an uncovered mutant')
            ->addArgument(
                'id',
                InputArgument::REQUIRED,
                'The mutant\'s id, or its first six or more characters; or a cluster\'s id',
            )
            ->addOption(
                self::STYLE,
                mode: InputOption::VALUE_REQUIRED,
                description: 'pest or phpunit, instead of the style of the nearest covering test',
            )
            ->addOption(
                FlowOptions::WRITE,
                mode: InputOption::VALUE_NONE,
                description: 'Add the test to its nearest covering test file, or create one; never overwrite',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use ($composition): int {
                $composed = $composition->compose($input);
                $id = $input->getArgument('id');
                $sought = IdPrefix::parse(is_string($id) ? $id : '');
                $style = self::style($input);

                return match (true) {
                    ! $composed instanceof Composed => Failed::because($output, $composed),
                    $sought instanceof CannotJudge => Failed::because($output, $sought),
                    $style instanceof CannotJudge => Failed::because($output, $style),
                    default => self::done(
                        $output,
                        $composed,
                        new Stubbing($composed)->stub($sought, $style),
                        $input->getOption(FlowOptions::WRITE) === true,
                    ),
                };
            });
    }

    /** The style `--style` asks for, none where it is not given, or why it is not one. */
    private static function style(InputInterface $input): AssertionStyle|Absent|CannotJudge
    {
        $asked = $input->getOption(self::STYLE);
        $written = is_string($asked) ? $asked : '';
        $style = is_string($asked) ? AssertionStyle::tryFrom($asked) : Absent::setting();

        return $style instanceof AssertionStyle || $style instanceof Absent
            ? $style
            : CannotJudge::because(sprintf(self::UNKNOWN_STYLE, $written));
    }

    private static function done(
        OutputInterface $output,
        Composed $composed,
        Stub|NoRecord|Ambiguous|CannotJudge $stub,
        bool $write,
    ): int {
        if (! $stub instanceof Stub) {
            return Failed::unfound($output, $stub);
        }

        $written = $write
            ? $composed->adapters->project->write($stub->file(), Contents::of($stub->contents()))
            : $stub;

        if ($written instanceof CannotJudge) {
            return Failed::because($output, $written);
        }

        $said = $written instanceof Written ? $stub->written() : $stub->printed();
        $output->writeln($said, OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }
}
