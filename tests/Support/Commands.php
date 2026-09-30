<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Cli\Console;
use NightWorksIO\MutationGate\Cli\FirstParty;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\Registry\Origin;
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
            new FirstParty()->extend(new Extensions(Origin::of(FirstParty::PACKAGE))),
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
        $tester = new CommandTester(self::console($project)->find($command));
        $code = $tester->execute($input, ['capture_stderr_separately' => true]);
        $output = $tester->getOutput();

        return new self(
            $code,
            Printed::by($output),
            Printed::by($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output),
        );
    }
}
