<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Command;

use function chdir;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Serve\ChildEnvironment;
use NightWorksIO\MutationGate\Adapter\Serve\Connection;
use NightWorksIO\MutationGate\Adapter\Serve\Daemon;
use NightWorksIO\MutationGate\Adapter\Serve\DaemonStart;
use NightWorksIO\MutationGate\Adapter\Serve\Forking;
use NightWorksIO\MutationGate\Adapter\Serve\FramedOutput;
use NightWorksIO\MutationGate\Adapter\Serve\ServeSocket;
use NightWorksIO\MutationGate\Cli\ExitCode;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Cli\Gate;
use NightWorksIO\MutationGate\Cli\Serve\ServedProject;
use NightWorksIO\MutationGate\Cli\Serve\Serving;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;
use NightWorksIO\MutationGate\Core\Serve\ServeIdentity;
use NightWorksIO\MutationGate\Core\Serve\ServeRefusal;
use NightWorksIO\MutationGate\Core\Serve\ServeRequest;
use NightWorksIO\MutationGate\Core\Serve\ServeStream;
use NightWorksIO\MutationGate\Core\Verb;

use function sprintf;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `mutation-gate serve` keeps the gate loaded for the project between runs
 * of `watch`, pre-commit and pre-push (ADR-0078). It listens on
 * `.mutation-gate/serve.sock` and runs each of those commands in a child it
 * forks, until no request has come for `local.serveIdle`, a client finds
 * the gate's version, composer.lock or the config changed, or
 * `serve --stop` asks it to stop.
 *
 * @internal
 */
final readonly class ServeCommand
{
    private const string NAME = Verb::Serve->value;

    private const string STOP = 'stop';

    private const string SERVING = 'Serving %s on %s. It stops after %s with no request, or on `serve --stop`.';

    private const string NONE = 'No daemon serves this project.';

    private const string UNFORKABLE
        = 'This process cannot fork a daemon\'s children; start the daemon with the gate\'s script.';

    private function __construct(
        private Composition $composition,
        private string $vendor,
        private Forking|NotGiven $forking,
    ) {
    }

    public static function command(
        Composition $composition,
        string $project,
        string $vendor,
        Forking|NotGiven $forking,
    ): Command {
        return new Command(self::NAME)
            ->setDescription('Keep the gate loaded for watch, pre-commit and pre-push')
            ->addOption(
                self::STOP,
                mode: InputOption::VALUE_NONE,
                description: 'Stop the daemon that serves this project',
            )
            ->addOption(
                DaemonStart::DETACHED,
                mode: InputOption::VALUE_NONE,
                description: 'Leave the session of the command that started it, as watch starts it',
            )
            ->setCode(static function (InputInterface $input, OutputInterface $output) use (
                $composition,
                $project,
                $vendor,
                $forking,
            ): int {
                $self = new self($composition, $vendor, $forking);
                $config = $input->getOption('config');
                $identity = ServedProject::identity($project, is_string($config) ? $config : NotGiven::value());

                return $input->getOption(self::STOP) === true
                    ? $self->stopped($identity, $output)
                    : $self->served($identity, $input, $output);
            });
    }

    private function stopped(ServeIdentity $identity, OutputInterface $output): int
    {
        $answer = Serving::client()->answered(ServeRequest::stopping($identity));
        $output->writeln($answer instanceof ServeRefusal ? $answer->why() : self::NONE, OutputInterface::OUTPUT_RAW);

        return ExitCode::Passed->value;
    }

    private function served(ServeIdentity $identity, InputInterface $input, OutputInterface $output): int
    {
        $forking = $this->forking;

        if (! $forking instanceof Forking) {
            return Failed::because($output, CannotJudge::because(self::UNFORKABLE));
        }

        $composed = $this->composition->composeLocally($input);

        if (! $composed instanceof Composed) {
            return Failed::because($output, $composed);
        }

        if ($input->getOption(DaemonStart::DETACHED) === true) {
            $forking->detached();
        }

        $idle = $composed->settings->local()->serveIdle();
        $socket = ServeSocket::ofProject();
        $output->writeln(
            sprintf(self::SERVING, $identity->root(), $socket->path(), $idle->text()),
            OutputInterface::OUTPUT_RAW,
        );
        $vendor = $this->vendor;
        $root = $identity->root();
        $ended = new Daemon(
            $socket,
            $identity,
            $idle,
            static fn(ServeRequest $request, Connection $connection): int
                => self::ran($request, $connection, $root, $vendor),
            $forking,
        )->served();

        return $ended instanceof CannotJudge ? Failed::because($output, $ended) : ExitCode::Passed->value;
    }

    /**
     * A request's command line run in the daemon's child, in the project's
     * root, as the client's process would have run it, its output sent on
     * the connection.
     */
    private static function ran(ServeRequest $request, Connection $connection, string $root, string $vendor): int
    {
        ChildEnvironment::madeAs($request->environment());
        chdir($root);
        $input = new ArgvInput($request->argv());
        Serving::reading($input, $request->input());

        return Gate::in($root, $vendor, OwnArchive::running())->run(
            $input,
            new FramedOutput($connection, ServeStream::Output, $request->isDecorated()),
            new FramedOutput($connection, ServeStream::Error, $request->isDecorated()),
        );
    }
}
