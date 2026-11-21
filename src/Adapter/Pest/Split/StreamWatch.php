<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_shift;

use Closure;

use function count;
use function is_file;
use function is_int;
use function microtime;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use NightWorksIO\MutationGate\Core\Runner\RunningProcesses;

use const PHP_INT_MAX;

use function unlink;
use function usleep;

/**
 * Pest's own process waiting for the streams of a pass (ADR-0182), at most a
 * set number running at once, the next queued started as one ends
 * (ADR-0188): a stream
 * whose parent ran a test past its deadline (see ParentDeadline) is stopped
 * with every process under it, and a stream of the tests it had not ended
 * but that one starts in its place, so no test holds a stream, its parent's
 * deadline starting after the slowdown the stopped one reached, and the
 * slowdown each stream measured itself at counted for the next pass
 * (ADR-0184).
 * Each stream that ended has its mark taken away, as one a signal ended
 * leaves it.
 *
 * @internal
 */
final readonly class StreamWatch
{
    /** How long it waits between two looks at the streams still running, in microseconds. */
    private const int BETWEEN = 50_000;

    public function __construct(
        private string $directory,
        private StreamSlowdown $slowdown,
        private RunningProcesses $running,
    ) {
    }

    /**
     * Waits until every stream ended, each stopped where its parent passed
     * its deadline, starting the next queued one, longest first, as soon as
     * fewer than this many run (ADR-0188): the results every stream wrote,
     * each started in a stopped one's place too.
     *
     * @param  list<WatchedStream|Closure(): WatchedStream> $streams each stream started, or what starts it
     * @param  int<1, max>                                  $most    the most streams that run at once
     * @return list<string>
     */
    public function ended(array $streams, int $most = PHP_INT_MAX): array
    {
        $results = [];
        $running = [];
        $queued = $streams;

        while ($running !== [] || $queued !== []) {
            [$running, $queued] = $this->filled($running, $queued, $most);
            [$still, $wrote] = $this->lookedAt($running);
            $results = [...$results, ...$wrote];
            $idle = count($still) === count($running);
            $running = $still;

            if ($running !== [] && ($idle || $queued === [])) {
                usleep(self::BETWEEN);
            }
        }

        return $results;
    }

    /**
     * The streams running once the queued ones are started, the first
     * first, until this many run.
     *
     * @param  list<WatchedStream>                          $running
     * @param  list<WatchedStream|Closure(): WatchedStream> $queued
     * @return array{list<WatchedStream>, list<WatchedStream|Closure(): WatchedStream>}
     */
    private function filled(array $running, array $queued, int $most): array
    {
        while ($queued !== [] && count($running) < $most) {
            $next = array_shift($queued);
            $running[] = $next instanceof WatchedStream ? $next : $next();
        }

        return [$running, $queued];
    }

    /**
     * One look at each running stream: those that run on, or in their place, and the results those that ended wrote.
     *
     * @param  list<WatchedStream>                           $running
     * @return array{list<WatchedStream>, list<string>}
     */
    private function lookedAt(array $running): array
    {
        $still = [];
        $results = [];

        foreach ($running as $stream) {
            [$left, $wrote] = $this->looked($stream);
            $still = [...$still, ...$left];
            $results = [...$results, ...$wrote];
        }

        return [$still, $results];
    }

    /**
     * One look at a stream: it runs on where it runs within its deadline;
     * it is stopped where its parent passed it, a stream started in its
     * place; and the results it wrote are taken once it ended.
     *
     * @return array{list<WatchedStream>, list<string>} what runs in its place, and the results it wrote
     */
    private function looked(WatchedStream $stream): array
    {
        $pid = $stream->pid;
        $mark = is_int($pid) ? ChildPlaces::markOf($this->directory, $pid) : '';
        $passed = $stream->process->isRunning() ? ParentDeadline::passedIn($mark, microtime(as_float: true)) : '';

        if ($passed === false) {
            return [[$stream], []];
        }

        if ($passed !== '' && is_int($pid)) {
            ProcessTable::stop($pid, $this->running);
        }

        $stream->waited();
        $slowdown = ParentDeadline::slowdownIn($mark);
        $this->slowdown->seen(ParentDeadline::measuredIn($mark));
        $this->unmarked($mark);
        $again = $passed === '' ? NotGiven::value() : $stream->againWithout($passed, $slowdown);

        return [$again instanceof WatchedStream ? [$again] : [], [$stream->results]];
    }

    private function unmarked(string $mark): void
    {
        if ($mark !== '' && is_file($mark)) {
            unlink($mark);
        }
    }
}
