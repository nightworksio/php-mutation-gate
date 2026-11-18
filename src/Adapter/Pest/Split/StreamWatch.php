<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function is_file;
use function is_int;
use function microtime;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\ProcessTable;
use Symfony\Component\Process\Process;

use function unlink;
use function usleep;

/**
 * Pest's own process waiting for the streams of a pass (ADR-0182): a stream
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

    public function __construct(private string $directory, private StreamSlowdown $slowdown)
    {
    }

    /**
     * Waits until every stream ended, each stopped where its parent passed
     * its deadline: the results every stream wrote, each started in a
     * stopped one's place too.
     *
     * @param  list<WatchedStream> $streams
     * @return list<string>
     */
    public function ended(array $streams): array
    {
        $results = [];

        while ($streams !== []) {
            $running = [];

            foreach ($streams as $stream) {
                [$left, $wrote] = $this->looked($stream);
                $running = [...$running, ...$left];
                $results = [...$results, ...$wrote];
            }

            $streams = $running;

            if ($streams !== []) {
                usleep(self::BETWEEN);
            }
        }

        return $results;
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
            ProcessTable::stop($pid, $this->ran(...));
        }

        $stream->waited();
        $slowdown = ParentDeadline::slowdownIn($mark);
        $this->slowdown->seen(ParentDeadline::measuredIn($mark));
        $this->unmarked($mark);
        $again = $passed === '' ? NotGiven::value() : $stream->againWithout($passed, $slowdown);

        return [$again instanceof WatchedStream ? [$again] : [], [$stream->results]];
    }

    /** @param list<string> $command */
    private function ran(array $command): string
    {
        $process = new Process($command);
        $process->run();

        return $process->getOutput();
    }

    private function unmarked(string $mark): void
    {
        if ($mark !== '' && is_file($mark)) {
            unlink($mark);
        }
    }
}
