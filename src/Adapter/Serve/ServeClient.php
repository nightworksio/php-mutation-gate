<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Serve;

use Closure;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Serve\ServeFrame;
use NightWorksIO\MutationGate\Core\Serve\ServeRefusal;
use NightWorksIO\MutationGate\Core\Serve\ServeRequest;
use NightWorksIO\MutationGate\Core\Serve\ServeStream;

/**
 * A client of the daemon that serves the project (ADR-0078): it sends a
 * request and writes what the daemon's child wrote, as it arrives, to its
 * own standard output and error, unchanged, so a decorated answer keeps its
 * colours.
 *
 * @internal
 */
final readonly class ServeClient
{
    private const string LOST = 'The daemon ended the run before it said its exit code.';

    /**
     * @param Closure(string): void $output writes what the child wrote to its output
     * @param Closure(string): void $error  writes what the child wrote to its error output
     */
    public function __construct(private ServeSocket $socket, private Closure $output, private Closure $error)
    {
    }

    /**
     * The command's exit code, its output written; why the daemon refused
     * the request, or why the request was not sent to what answers; none,
     * where no daemon listens; or why the answer broke off. The request goes
     * only to a socket and a peer the user owns (see ServeSocket).
     */
    public function answered(ServeRequest $request): int|ServeRefusal|NotGiven|CannotJudge
    {
        $connection = $this->socket->connected();

        if (! $connection instanceof Connection) {
            return $connection;
        }

        $connection->sent($request->line());

        return $this->read($connection);
    }

    private function read(Connection $connection): int|ServeRefusal|CannotJudge
    {
        while (is_string($line = $connection->line())) {
            $frame = ServeFrame::readFrom($line);
            $ended = $frame instanceof ServeFrame ? $this->passed($frame) : NotGiven::value();

            if (! $ended instanceof NotGiven) {
                return $ended;
            }
        }

        return CannotJudge::because(self::LOST);
    }

    /** A frame's text written where its stream goes; or the end of the answer it carries. */
    private function passed(ServeFrame $frame): int|ServeRefusal|NotGiven
    {
        return match ($frame->stream()) {
            ServeStream::Output => $this->wrote($this->output, $frame),
            ServeStream::Error => $this->wrote($this->error, $frame),
            ServeStream::Exit => $frame->code(),
            ServeStream::Refusal => ServeRefusal::because($frame->text()),
        };
    }

    /** @param Closure(string): void $stream */
    private function wrote(Closure $stream, ServeFrame $frame): NotGiven
    {
        $stream($frame->text());

        return NotGiven::value();
    }
}
