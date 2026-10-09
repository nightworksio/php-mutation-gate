<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Cli\Console;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Registry\Origin;
use NightWorksIO\MutationGate\Core\ThisPackage;
use NightWorksIO\MutationGate\Extension\Extensions;

use function sprintf;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/** The commands of a project's command line, run as a test runs them. */
final readonly class Commands
{
    private function __construct(
        public int $code,
        public string $output,
        public string $errors,
    ) {
    }

    /**
     * The command line of a project, with this package's own extension, at the instant every test runs at,
     * started in these environment variables.
     *
     * @param array<string, string> $environment
     */
    public static function console(string $project, array $environment = []): Application
    {
        return Console::application(
            new FirstParty()->extend(new Extensions(Origin::of(ThisPackage::COMPOSER))),
            $project,
            sprintf('%s/vendor', $project),
            new StoppedClock(Configs::NOW),
            Variables::of($environment),
        );
    }

    /**
     * One command run in a project, with its exit code and what it printed on each stream.
     *
     * @param array<mixed> $input
     */
    public static function run(string $project, string $command, array $input = []): self
    {
        return self::answering($project, $command, $input, []);
    }

    /**
     * One command run in a project, as a person at a terminal runs it, giving
     * these answers to its questions in turn; with none, as a command that
     * takes no answers runs.
     *
     * @param array<mixed> $input
     * @param list<string> $answers
     */
    public static function answering(string $project, string $command, array $input, array $answers): self
    {
        $tester = new CommandTester(self::console($project)->find($command));
        $tester->setInputs($answers);
        $code = $tester->execute($input, ['capture_stderr_separately' => true, 'interactive' => $answers !== []]);
        $output = $tester->getOutput();

        return new self(
            $code,
            Printed::by($output),
            Printed::by($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output),
        );
    }
}
