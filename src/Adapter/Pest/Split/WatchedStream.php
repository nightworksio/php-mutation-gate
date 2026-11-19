<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_diff;
use function array_values;

use Closure;

use function explode;
use function file_get_contents;
use function is_array;
use function is_file;
use function json_decode;

use NightWorksIO\MutationGate\Core\NotGiven;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/**
 * One stream of a pass as Pest's own process waits for it (ADR-0182): its
 * process and its id, the results it writes, the tests it runs where they are named by
 * their ids, and how a stream of some of them is started in its place.
 *
 * @internal
 */
final readonly class WatchedStream
{
    /** The id of the stream's process, read as it started, since a process that ended has none. */
    public ?int $pid;

    /**
     * @param list<string>                                 $tests the tests it runs, by their ids; none for whole files
     * @param Closure(list<string>, float): (WatchedStream|NotGiven) $again what starts a stream of some of them in its
     *                                                                place, its parent's deadline at a slowdown after
     *                                                                this one's
     */
    public function __construct(
        public Process $process,
        public string $results,
        private array $tests,
        private Closure $again,
    ) {
        $this->pid = $process->getPid();
    }

    /** Waits for the stream to end, however it ends: one a signal ended leaves what it wrote. */
    public function waited(): void
    {
        try {
            $this->process->wait();
        } catch (ProcessSignaledException) {
            return;
        }
    }

    /**
     * A stream started in this one's place, once it was stopped in this
     * test at this slowdown, of each of its tests its parent had not ended
     * but that one; none where it runs whole files, or no test is left.
     */
    public function againWithout(string $stopped, float $slowdown): self|NotGiven
    {
        $left = array_values(array_diff($this->tests, [...$this->ended(), $stopped]));

        return $this->tests === [] || $left === [] ? NotGiven::value() : ($this->again)($left, $slowdown);
    }

    /**
     * The tests the stream's parent ended, as its results say.
     *
     * @return list<string>
     */
    private function ended(): array
    {
        $text = is_file($this->results) ? (string) file_get_contents($this->results) : '';
        $ended = [];

        foreach (explode("\n", $text) as $line) {
            $read = json_decode($line, associative: true);
            $read = is_array($read) ? StreamRead::of($read) : null;

            if ($read instanceof StreamRead && $read->kind === StreamLine::PARENT) {
                $ended[] = $read->test;
            }
        }

        return $ended;
    }
}
