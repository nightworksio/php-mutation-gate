<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Warm;

use function class_exists;

use Closure;

use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function get_included_files;
use function getmypid;
use function in_array;
use function interface_exists;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;

use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\Baseline;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\Database\WarmDatabase;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\PhpFile;
use NightWorksIO\MutationGate\Core\Runner\Polling;

use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function posix_kill;
use function realpath;
use function sprintf;
use function strval;
use function trait_exists;

/**
 * A warm worker of a Pest run (ADR-0023), in one place among
 * the run's: started by Pest's parent process once that place's first
 * mutant's run starts, it loads nothing of the project, and serves each run
 * its place asks for in a child it forks for it, which runs Pest's script as
 * a fresh process would, so the worker itself never runs a test. Each child
 * readies its test databases from the baseline the worker keeps, and the
 * worker keeps what the child reports. A run of a mutant of a file the worker
 * loaded, one of the gate's own, or of a file that declares a class or function
 * the worker declared from another, starts fresh. The worker ends once its
 * parent is gone, or the gate says the run is done.
 *
 * @internal
 */
final readonly class Worker
{
    private const string LOADED = 'The warm worker had loaded %s, which a mutant changes, so its mutants ran fresh.';

    private const string DECLARED = 'The warm worker had declared %s, which %s declares, so its mutants ran fresh.';

    private const string UNFORKED = 'The warm worker could not fork, so a mutant ran fresh.';

    private const string LOST = 'The warm worker lost track of a child, so its mutant ran fresh.';

    /** @param Closure(int): void $pause pauses this many microseconds between looks, as usleep does */
    private function __construct(private Seat $seat, private int $parent, private Closure $pause)
    {
    }

    /**
     * The worker in this place of the directory the run's workers share, for
     * this parent, pausing as this does between looks.
     *
     * @param Closure(int): void $pause pauses this many microseconds, as usleep does
     */
    public static function at(string $directory, int $place, int $parent, Closure $pause): self
    {
        return new self(Seat::at($directory, $place), $parent, $pause);
    }

    /** Serves each run asked for until the run is done, its process id written for its clients: its exit code. */
    public function served(Forking $forking): int
    {
        WholeFile::written($this->seat->pid(), strval(getmypid()));
        $baseline = NotGiven::value();

        while ($this->isWanted()) {
            $request = Request::takenFrom($this->seat->request());
            $baseline = $request instanceof Request
                ? $this->ran($request, $baseline, $forking)
                : $this->idled($baseline);
        }

        return 0;
    }

    /** The run in a child, unless its asker is gone, and the baseline the worker keeps after it. */
    private function ran(Request $request, Baseline|NotGiven $baseline, Forking $forking): Baseline|NotGiven
    {
        $alive = $this->isAlive($request->asker());
        $loaded = $alive ? $this->readied($request) : '';

        if (! $alive || $loaded !== '') {
            $this->refused($request, $loaded);

            return $baseline;
        }

        $child = $forking->forked($request, $baseline, $this->seat->report());

        if (! is_int($child)) {
            $this->refused($request, self::UNFORKED);

            return $baseline;
        }

        WholeFile::written($this->seat->child($request->id()), strval($child));

        $finish = $this->waited($child, $request->asker());
        $kept = WarmDatabase::after($baseline, $this->seat->report());
        $finish instanceof Finish
            ? $finish->writtenTo($this->seat->ended($request->id()))
            : $this->refused($request, self::LOST);

        return $kept;
    }

    /** No child for the run, for this reason, which is warned of; none for a run whose asker has gone. */
    private function refused(Request $request, string $reason): void
    {
        if ($reason !== '') {
            file_put_contents($this->seat->warned(), sprintf("%s\n", $reason), FILE_APPEND);
            Finish::refused($reason)->writtenTo($this->seat->ended($request->id()));
        }
    }

    /**
     * The run readied to fork, where its output starts noted; and why it
     * cannot fork, where the worker has by then loaded the file its mutant
     * changes; nothing where it can.
     */
    private function readied(Request $request): string
    {
        Offsets::now($this->seat)->writtenTo($this->seat->started($request->id()));

        return $this->loaded($request->mutated());
    }

    /**
     * Why a run of a mutant of this file cannot fork from the worker: the
     * worker loaded the file, by the time it would fork, so a child could not
     * load the mutant in its place; nothing where it did not.
     */
    private function loaded(string $mutated): string
    {
        $real = realpath($mutated);
        $isLoaded = $real !== false && in_array($real, get_included_files(), strict: true);
        $declared = $real !== false && is_file($real) ? $this->declared($real) : '';

        return match (true) {
            $isLoaded => sprintf(self::LOADED, $real),
            $declared !== '' => sprintf(self::DECLARED, $declared, $real),
            default => '',
        };
    }

    /**
     * The first name a file declares that the worker has declared already,
     * from that file or any other, as the gate running a run of its own
     * source declares its own classes; none where it has none.
     */
    private function declared(string $file): string
    {
        $text = file_get_contents($file);

        foreach (is_string($text) ? PhpFile::read(Contents::of($text))->declares()->all() : [] as $name) {
            $isDeclared = class_exists($name, autoload: false)
                || interface_exists($name, autoload: false)
                || trait_exists($name, autoload: false)
                || function_exists($name);

            if ($isDeclared) {
                return $name;
            }
        }

        return '';
    }

    /**
     * How the child ended; stopped, with every process under it, once its
     * asker is gone; or none, where the system no longer tells of it.
     */
    private function waited(int $child, int $asker): Finish|NotGiven
    {
        $waited = pcntl_waitpid($child, $status, WNOHANG);

        while ($waited === 0 && $this->isAlive($asker)) {
            ($this->pause)(Polling::interval()->microseconds());
            $waited = pcntl_waitpid($child, $status, WNOHANG);
        }

        if ($waited === 0) {
            WholeTree::stopped($child);
            pcntl_waitpid($child, $status);
        }

        if ($waited === -1 || ! is_int($status)) {
            return NotGiven::value();
        }

        $to = Offsets::now($this->seat);

        return pcntl_wifsignaled($status)
            ? Finish::signalled((int) pcntl_wtermsig($status), $to)
            : Finish::exited((int) pcntl_wexitstatus($status), $to);
    }

    /** Whether the run still wants the worker: its directory is there, it is not done, and the parent lives. */
    private function isWanted(): bool
    {
        return is_dir($this->seat->directory()) && ! is_file($this->seat->stop()) && $this->isAlive($this->parent);
    }

    private function idled(Baseline|NotGiven $baseline): Baseline|NotGiven
    {
        ($this->pause)(Polling::interval()->microseconds());

        return $baseline;
    }

    /** Whether the process is still there: signal 0 tells, and sends nothing. */
    private function isAlive(int $pid): bool
    {
        return posix_kill($pid, 0);
    }
}
