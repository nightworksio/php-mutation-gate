<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use Closure;

use function explode;
use function file_get_contents;
use function getcwd;
use function getenv;
use function getmypid;
use function hrtime;
use function is_executable;
use function is_file;
use function is_int;
use function max;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Runner\Ran;

use function pcntl_exec;
use function posix_kill;
use function sprintf;
use function str_contains;
use function unlink;

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
    /**
     * @param Closure(int): void    $pause  pauses this many microseconds between looks, as usleep does
     * @param Closure(string): void $out    prints on the run's standard output
     * @param Closure(string): void $errors prints on the run's standard error
     */
    private function __construct(
        private Seat $seat,
        private Closure $pause,
        private Closure $out,
        private Closure $errors,
    ) {
    }

    /**
     * The client of this place of the directory the run's workers share,
     * pausing and printing as these do.
     *
     * @param Closure(int): void    $pause  pauses this many microseconds between looks, as usleep does
     * @param Closure(string): void $out    prints on the run's standard output
     * @param Closure(string): void $errors prints on the run's standard error
     */
    public static function at(string $directory, int $place, Closure $pause, Closure $out, Closure $errors): self
    {
        return new self(Seat::at($directory, $place), $pause, $out, $errors);
    }

    /**
     * The run of Pest's script with these arguments: its exit code.
     *
     * @param list<string> $arguments
     */
    public function ran(string $script, array $arguments): int
    {
        $environment = getenv();
        $request = Request::of(
            sprintf('%d-%d', getmypid(), hrtime(as_number: true)),
            (int) getmypid(),
            [$script, ...$arguments],
            $environment,
            (string) getcwd(),
        );
        $finish = PidFile::isRunning($this->seat->pid()) && $request->leftIn($this->seat->request())
            ? $this->awaited($request)
            : NotGiven::value();

        return $finish instanceof Finish && ! $finish->isRefused()
            ? $this->endedAs($finish)
            : $this->fresh($script, $arguments, $environment);
    }

    /** How the worker ran the request, printing what it printed; none where the worker went first. */
    private function awaited(Request $request): Finish|NotGiven
    {
        $finish = NotGiven::value();
        $printed = NotGiven::value();

        while ($finish instanceof NotGiven && PidFile::isRunning($this->seat->pid())) {
            ($this->pause)(Polling::interval()->microseconds());
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
        ($this->out)($this->bytes($this->seat->out(), $from->out(), $to->out()));
        ($this->errors)($this->bytes($this->seat->err(), $from->err(), $to->err()));

        return $to;
    }

    /**
     * The child the worker forked for the run stopped, with every process
     * under it, where the worker went before the run ended, so no run of the
     * mutant goes on beside the fresh one.
     */
    private function orphanStopped(Request $request): void
    {
        $child = PidFile::read($this->seat->child($request->id()));

        if (is_int($child)) {
            WholeTree::stopped($child);
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

    /** The code a run ended with, ended by the signal that ended its child where one did. */
    private function endedAs(Finish $finish): int
    {
        $signal = $finish->signal();

        if (is_int($signal)) {
            posix_kill((int) getmypid(), $signal);
        }

        return $finish->exitCode();
    }

    /**
     * The run started fresh, as Pest starts it, in place of this process.
     *
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     */
    private function fresh(string $script, array $arguments, array $environment): int
    {
        pcntl_exec($this->found($script), $arguments, $environment);

        return Ran::UNSTARTED;
    }

    /** A script's path: as named where the name holds a directory, else the first of the system's path it is in. */
    private function found(string $script): string
    {
        $directories = str_contains($script, '/') ? [] : explode(':', (string) getenv('PATH'));

        foreach ($directories as $directory) {
            $path = sprintf('%s/%s', $directory, $script);

            if (is_executable($path)) {
                return $path;
            }
        }

        return $script;
    }

    /** The bytes of one of the worker's output files between two offsets. */
    private function bytes(string $file, int $from, int $to): string
    {
        return (string) file_get_contents($file, offset: $from, length: max(0, $to - $from));
    }
}
