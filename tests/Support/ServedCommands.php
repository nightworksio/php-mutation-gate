<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_filter;
use function array_keys;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use NightWorksIO\MutationGate\Adapter\Serve\Connection;
use NightWorksIO\MutationGate\Adapter\Serve\Daemon;
use NightWorksIO\MutationGate\Adapter\Serve\Forking;
use NightWorksIO\MutationGate\Cli\Command\ServeCommand;
use NightWorksIO\MutationGate\Cli\Serve\ServedProject;
use NightWorksIO\MutationGate\Cli\Serve\Serving;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Core\Serve\ServeRequest;
use NightWorksIO\MutationGate\Core\Serve\ServeStream;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Tests\Fakes\ProofStoreFake;

use function register_shutdown_function;
use function sprintf;

use Symfony\Component\Console\Tester\CommandTester;

/** The command line's side of `mutation-gate serve` in the tests: its serving, its command, and a daemon to serve. */
final readonly class ServedCommands
{
    /** Where a daemon stood in for keeps what it was asked, in the project it serves. */
    private const string ASKED = 'asked.json';

    /** The serving of a flows project, starting a daemon by this script. */
    public static function serving(string $project, string $script = '/nowhere'): Serving
    {
        return new Serving(
            FlowCommands::composition($project, ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci()),
            $project,
            $script,
        );
    }

    /**
     * `serve` run in a flows project, changed into, forking this way, with these options: its exit code and what it
     * printed.
     *
     * @param  array<string, bool> $options
     * @return array{int, string}
     */
    public static function serve(string $project, Forking|NotGiven $forking, array $options = []): array
    {
        $command = ServeCommand::command(
            FlowCommands::composition($project, ScriptedRunner::fixture(), new ProofStoreFake(), Flows::ci()),
            $project,
            sprintf('%s/vendor', $project),
            $forking,
        );
        $command->setApplication(Commands::console($project));
        $tester = new CommandTester($command);
        $code = $tester->execute($options);

        return [$code, Printed::by($tester->getOutput())];
    }

    /**
     * `serve` run in a flows project with these settings, changed into, forking this way, with these options.
     *
     * @param  array<string, bool> $options
     * @return array{int, string}
     */
    public static function serveWith(string $config, Forking|NotGiven $forking, array $options = []): array
    {
        return self::serve(ServedProjects::enteredAt(FlowCommands::project($config)), $forking, $options);
    }

    /** A daemon of the project, in a process of its own, that keeps what each request asked and answers with this code. */
    public static function servedAside(string $project, int $code): void
    {
        $child = ServedProjects::aside(static function () use ($project, $code): void {
            new Daemon(
                ServedProjects::socket(),
                ServedProject::identity($project, NotGiven::value()),
                Seconds::of(2.0),
                static function (ServeRequest $request, Connection $connection) use ($code): int {
                    $connection->sent(ServeFrame::written(ServeStream::Output, '')->line());
                    $connection->sent(ServeFrame::written(ServeStream::Error, '')->line());
                    file_put_contents(self::ASKED, (string) json_encode([
                        'argv' => $request->argv(),
                        'environment' => $request->environment(),
                        'input' => $request->input(),
                    ]));

                    return $code;
                },
                new InlineForking(),
            )->served();
        });
        register_shutdown_function(static fn() => ServedProjects::waitedFor($child));
        ServedProjects::until(static fn(): bool => ServedProjects::socket()->isThere());
    }

    /**
     * What the daemon stood in for was last asked: the command line, the names of the variables sent, and what was
     * read on standard input.
     *
     * @return array{list<string>, list<string>, string}
     */
    public static function asked(): array
    {
        $asked = json_decode((string) file_get_contents(self::ASKED), associative: true);
        $asked = is_array($asked) ? $asked : [];
        $argv = is_array($asked['argv'] ?? null) ? $asked['argv'] : [];
        $environment = is_array($asked['environment'] ?? null) ? $asked['environment'] : [];

        return [
            array_values(array_filter($argv, is_string(...))),
            array_values(array_filter(array_keys($environment), is_string(...))),
            is_string($asked['input'] ?? null) ? $asked['input'] : '',
        ];
    }
}
