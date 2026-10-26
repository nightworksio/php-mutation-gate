<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function array_slice;
use function explode;
use function file_get_contents;
use function fwrite;
use function getcwd;
use function getenv;
use function getmypid;
use function hrtime;
use function implode;
use function is_executable;
use function is_file;
use function is_int;
use function max;

use NightWorksIO\MutationGate\Core\Format\Kind;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Runner\Ran;

use function pcntl_exec;
use function posix_kill;
use function sprintf;
use function str_contains;
use function unlink;
use function usleep;

/**
 * A mutant's own run, as Pest's parent starts it where its place has a warm
 * worker (ADR-0023): the process Pest watches, which asks the worker for the
 * run, prints what the run prints on the stream it prints it on as it
 * prints it, and ends as the run ended. Where the worker forks the run no
 * child, or is gone, the client becomes the run Pest would have started,
 * in place, so Pest stops the run where it stops the client.
 */
final readonly class Client
{
    /** The code of a run whose script could not be started, as a shell's is. */
    private const int UNSTARTED = 127;

    private function __construct(private Seat $seat)
    {
    }

    public static function at(string $directory, int $place): self
    {
        return new self(Seat::at($directory, $place));
    }

    /**
     * The run of this command line, Pest's script first: its exit code.
     *
     * @param list<string> $command
     */
    public function ran(array $command): int
    {
        $environment = getenv();
        $request = Request::of(
            sprintf('%d-%d', getmypid(), hrtime(as_number: true)),
            (int) getmypid(),
            $command,
            $environment,
            (string) getcwd(),
        );
        $finish = $this->isServing() && $request->leftIn($this->seat->request())
            ? $this->awaited($request)
            : NotGiven::value();

        return $finish instanceof Finish && ! $finish->isRefused()
            ? $this->endedAs($finish)
            : $this->fresh($command, $environment);
    }

    /** How the worker ran the request, printing what it printed; none where the worker went first. */
    private function awaited(Request $request): Finish|NotGiven
    {
        $finish = NotGiven::value();
        $printed = NotGiven::value();

        while ($finish instanceof NotGiven && $this->isServing()) {
            usleep(Polling::interval()->microseconds());
            $printed = $printed instanceof Offsets
                ? $this->copied($printed, Offsets::now($this->seat))
                : Offsets::readFrom($this->seat->started($request->id()));
            $finish = Finish::readFrom($this->seat->ended($request->id()));
        }

        $from = $printed instanceof Offsets ? $printed : Offsets::readFrom($this->seat->started($request->id()));

        if ($finish instanceof Finish && ! $finish->isRefused() && $from instanceof Offsets) {
            $this->copied($from, $finish->to());
        }

        if ($finish instanceof NotGiven) {
            $this->orphanStopped($request);
        }

        $this->forgotten($request);

        return $finish;
    }

    /** Prints what the worker's files grew by between the offsets, each on its stream: where they stand now. */
    private function copied(Offsets $from, Offsets $to): Offsets
    {
        fwrite(STDOUT, $this->bytes($this->seat->out(), $from->out(), $to->out()));
        fwrite(STDERR, $this->bytes($this->seat->err(), $from->err(), $to->err()));

        return Offsets::of(max($from->out(), $to->out()), max($from->err(), $to->err()));
    }

    /**
     * The child the worker forked for the run stopped, with every process
     * under it, where the worker went before the run ended, so no run of the
     * mutant goes on beside the fresh one.
     */
    private function orphanStopped(Request $request): void
    {
        $child = WholeFile::read($this->seat->child($request->id()));

        if ($child instanceof Node && $child->kind() === Kind::Integer) {
            WholeTree::stopped($child->integer());
        }
    }

    /** The request's files removed, and the request too where the worker never took it. */
    private function forgotten(Request $request): void
    {
        $files = [
            $this->seat->started($request->id()),
            $this->seat->child($request->id()),
            $this->seat->ended($request->id()),
            $this->seat->request(),
        ];

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** Whether the place's worker serves runs: it is still there. */
    private function isServing(): bool
    {
        $pid = is_file($this->seat->pid()) ? (int) file_get_contents($this->seat->pid()) : 0;

        return $pid > 0 && posix_kill($pid, 0);
    }

    /** The code a run ended with: the one it exited with, or the one a shell gives the signal that ended it. */
    private function endedAs(Finish $finish): int
    {
        $signal = $finish->signal();
        $code = $finish->code();

        if (is_int($signal)) {
            posix_kill((int) getmypid(), $signal);
        }

        return match (true) {
            is_int($signal) => Ran::SIGNALLED + $signal,
            is_int($code) => $code,
            default => 1,
        };
    }

    /**
     * The run started fresh, as Pest starts it, in place of this process.
     *
     * @param list<string>          $command
     * @param array<string, string> $environment
     */
    private function fresh(array $command, array $environment): int
    {
        pcntl_exec($this->found(implode('', array_slice($command, 0, 1))), array_slice($command, 1), $environment);

        return self::UNSTARTED;
    }

    /** A script's path: as named where the name holds a directory, else the first of the system's path it is in. */
    private function found(string $script): string
    {
        if (str_contains($script, '/')) {
            return $script;
        }

        foreach (explode(':', (string) getenv('PATH')) as $directory) {
            $path = sprintf('%s/%s', $directory, $script);
            if (is_executable($path)) {
                return $path;
            }
        }

        return $script;
    }

    /** The bytes of a file between two offsets. */
    private function bytes(string $file, int $from, int $to): string
    {
        $length = max(0, $to - $from);
        $bytes = $length > 0 && is_file($file) ? file_get_contents($file, offset: $from, length: $length) : '';

        return $bytes === false ? '' : $bytes;
    }
}
