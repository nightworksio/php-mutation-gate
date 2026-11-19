<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function implode;
use function is_array;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Local\MeasuredProgram;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Cores;
use NightWorksIO\MutationGate\Cli\Flow\Machines;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verb;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `machine [-- <program>…]`: this machine's processor and cores as one JSON
 * object, reading no config and running no test. Given a program, it runs
 * it once untimed, then {@see Machines::ROUNDS} times, each round timed from
 * the program's start to its exit and nothing else, and adds the rounds and
 * their median seconds (ADR-0159). The program's output goes to the error
 * stream. It exits 0, and 2 where a round of the program did not end with 0.
 *
 * @internal
 */
final readonly class MachineCommand
{
    private const string NAME = Verb::Machine->value;

    private const string PROGRAM = 'program';

    private const string FAILED = 'mutation-gate machine: a round of `%s` did not end with 0.';

    public static function command(): Command
    {
        return new Command(self::NAME)
            ->setDescription('Print this machine\'s processor and cores, and how long a program after -- takes on it')
            ->addArgument(
                self::PROGRAM,
                InputArgument::IS_ARRAY,
                'The program to time and its arguments, after --: one untimed round, then the median of the timed ones',
            )
            ->setCode(static fn(InputInterface $input, OutputInterface $output): int => self::run($input, $output));
    }

    private static function run(InputInterface $input, OutputInterface $output): int
    {
        $program = self::program($input);
        $seconds = $program === []
            ? NotGiven::value()
            : Machines::timed(static fn(): bool => MeasuredProgram::exitOf($program) === ExitCode::Passed->value);

        if ($program !== [] && ! $seconds instanceof Seconds) {
            return Failed::because($output, CannotJudge::because(sprintf(self::FAILED, implode(' ', $program))));
        }

        $cpu = Machines::cpuIn(Machines::CPUINFO);
        $printed = JsonText::compact([
            ...$cpu instanceof NotGiven ? [] : ['cpu' => $cpu],
            'cores' => Cores::counted()->count(),
            ...$seconds instanceof Seconds ? ['rounds' => Machines::ROUNDS, 'seconds' => $seconds->seconds()] : [],
        ]);
        $output->write(sprintf("%s\n", $printed), options: OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }

    /** @return list<string> */
    private static function program(InputInterface $input): array
    {
        $given = $input->getArgument(self::PROGRAM);
        $program = [];

        foreach (is_array($given) ? $given : [] as $word) {
            if (is_string($word)) {
                $program[] = $word;
            }
        }

        return $program;
    }
}
