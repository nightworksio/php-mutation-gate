<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Serve;

use function fopen;
use function fwrite;
use function getenv;
use function in_array;
use function is_int;
use function is_resource;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Serve\DaemonStart;
use NightWorksIO\MutationGate\Adapter\Serve\ServeClient;
use NightWorksIO\MutationGate\Adapter\Serve\ServeSocket;
use NightWorksIO\MutationGate\Cli\Command\Aside;
use NightWorksIO\MutationGate\Cli\Command\Failed;
use NightWorksIO\MutationGate\Cli\Flow\Composed;
use NightWorksIO\MutationGate\Cli\Flow\Composition;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Local;
use NightWorksIO\MutationGate\Core\Hook\Hook;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServedVariables;
use NightWorksIO\MutationGate\Core\Serve\ServeRefusal;
use NightWorksIO\MutationGate\Core\Serve\ServeRequest;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verb;

use function rewind;
use function sprintf;

use const STDIN;

use function stream_get_contents;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `watch`, pre-commit and pre-push handed to the daemon that serves the
 * project (ADR-0078), where one runs: the command line, the
 * variables a daemon takes (see ServedVariables) and, for pre-push, git's
 * lines on standard input are sent to it, once the socket and its peer are
 * found to be the user's own, and its answer is written as it arrives. `watch` starts a daemon where
 * none runs, or where the one running refused it, and `local.serveStart`
 * says to; the hooks only use one already running. Where the daemon refuses
 * the request, the command runs in this process, and says why.
 *
 * @internal
 */
final readonly class Serving
{
    private const string REFUSED = '%s So this command runs on its own.';

    private const string STDIN_OPTION = '--stdin';

    private const string MEMORY = 'php://memory';

    private const string READ_WRITE = 'r+';

    public function __construct(private Composition $composition, private string $project, private string $script)
    {
    }

    /**
     * The exit code of the command as the daemon ran it; or none, where it
     * runs in this process: it is not one a daemon serves, this process is a
     * daemon's child, no daemon runs and none is to start, or the daemon
     * refused it.
     */
    public function forwarded(string $command, InputInterface $input, OutputInterface $output): int|NotGiven
    {
        $watch = $command === Verb::Watch->value;
        $served = $watch || in_array($command, [Hook::PreCommit->value, Hook::PrePush->value], strict: true);

        if (! $served || ! $input instanceof ArgvInput || is_string(getenv(ServedVariables::SERVED))) {
            return NotGiven::value();
        }

        $request = $this->request($command, $input, $output);
        $outcome = $this->outcome($this->answered($request, $watch, $input, $output), $output);

        if ($outcome instanceof NotGiven) {
            self::reading($input, $request->input());
        }

        return $outcome;
    }

    /** The command line, reading this as its standard input, where it is not empty. */
    public static function reading(ArgvInput $input, string $read): void
    {
        $stream = fopen(self::MEMORY, self::READ_WRITE);

        if ($read !== '' && is_resource($stream)) {
            fwrite($stream, $read);
            rewind($stream);
            $input->setStream($stream);
        }
    }

    /** A client of the project's daemon that writes its answers to this output and its error output. */
    public static function client(OutputInterface $output): ServeClient
    {
        return new ServeClient(
            ServeSocket::ofProject(),
            static function (string $text) use ($output): void {
                $output->write($text, options: OutputInterface::OUTPUT_RAW);
            },
            static function (string $text) use ($output): void {
                Aside::of($output)->write($text, options: OutputInterface::OUTPUT_RAW);
            },
        );
    }

    private function request(string $command, ArgvInput $input, OutputInterface $output): ServeRequest
    {
        $config = $input->getParameterOption('--config', null, onlyParams: true);
        $handed = $input->hasParameterOption(self::STDIN_OPTION, onlyParams: true);
        $stream = $input->getStream();
        $pushed = $command === Hook::PrePush->value && ! $handed
            ? (string) stream_get_contents(is_resource($stream) ? $stream : STDIN)
            : '';

        return ServeRequest::running(
            ServedProject::identity($this->project, is_string($config) ? $config : NotGiven::value()),
            [$this->script, ...$input->getRawTokens()],
            ServedVariables::of(getenv()),
            $pushed,
            $output->isDecorated(),
        );
    }

    /**
     * The daemon's answer, from a daemon `watch` starts where none answered
     * or the one running refused it, and `local.serveStart` lets it.
     */
    private function answered(
        ServeRequest $request,
        bool $watch,
        InputInterface $input,
        OutputInterface $output,
    ): int|ServeRefusal|NotGiven|CannotJudge {
        $client = self::client($output);
        $answer = $client->answered($request);
        $unserved = $answer instanceof NotGiven || $answer instanceof ServeRefusal;
        $wait = $watch && $unserved ? $this->startWait($input) : NotGiven::value();
        $started = $wait instanceof Seconds
            && new DaemonStart(ServeSocket::ofProject(), $wait)->started($this->script, $this->project);

        return $started ? $client->answered($request) : $answer;
    }

    /**
     * How long `watch` waits for a daemon it starts, `local.serveStartWait`,
     * where `local.serveStart` lets it start one; none where it may not.
     */
    private function startWait(InputInterface $input): Seconds|NotGiven
    {
        $composed = $this->composition->composeLocally($input);
        $local = $composed instanceof Composed ? $composed->settings->local() : NotGiven::value();

        return $local instanceof Local && $local->serveStart()->startsForWatch()
            ? $local->serveStartWait()
            : NotGiven::value();
    }

    private function outcome(int|ServeRefusal|NotGiven|CannotJudge $answer, OutputInterface $output): int|NotGiven
    {
        if ($answer instanceof ServeRefusal) {
            Aside::of($output)->writeln(sprintf(self::REFUSED, $answer->why()), OutputInterface::OUTPUT_RAW);
        }

        return match (true) {
            is_int($answer) => $answer,
            $answer instanceof CannotJudge => Failed::because($output, $answer),
            default => NotGiven::value(),
        };
    }
}
