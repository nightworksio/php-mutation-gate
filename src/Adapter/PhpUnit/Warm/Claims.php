<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit\Warm;

use function fclose;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function is_file;
use function is_resource;
use function microtime;

use NightWorksIO\MutationGate\Core\NotGiven;

use function rewind;
use function stream_get_contents;
use function strval;

/**
 * The runs a job's workers have claimed, counted in one file each worker
 * takes in turn under an exclusive lock, so no run is claimed twice and they
 * are claimed in their order: the next is claimed only where one is left and
 * the job's end has not come.
 */
final readonly class Claims
{
    private function __construct(private string $counter, private int $count, private float|NotGiven $end)
    {
    }

    public static function of(Workplace $workplace, Job $job): self
    {
        return new self($workplace->claimed(), $job->count(), $job->end());
    }

    /** The position of the next run, now this worker's; or none, where none is left or the end has come. */
    public function next(): int|NotGiven
    {
        $file = is_file($this->counter) ? fopen($this->counter, 'c+') : false;

        if (! is_resource($file) || ! flock($file, LOCK_EX)) {
            return NotGiven::value();
        }

        $at = (int) stream_get_contents($file);
        $open = $at < $this->count && ($this->end instanceof NotGiven || microtime(as_float: true) < $this->end);

        if ($open) {
            rewind($file);
            ftruncate($file, 0);
            fwrite($file, strval($at + 1));
        }

        fclose($file);

        return $open ? $at : NotGiven::value();
    }
}
