<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Process;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Overrun;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\TestLog;

/**
 * A run's file of test events, read as it grows, for the watchdog that
 * holds each of its tests to a limit of its own (ADR-0090); none where the
 * run's silence limit watches no test.
 *
 * @internal
 */
final class TestEventsFile
{
    /** How many bytes of the file have been read. */
    private int $read = 0;

    private function __construct(private readonly string $file, private TestLog $log)
    {
    }

    /** The file of test events a run under this silence limit writes, where the limit watches its tests. */
    public static function of(SilenceLimit|NotGiven $silence): self|NotGiven
    {
        if (! $silence instanceof SilenceLimit) {
            return $silence;
        }

        $log = TestLog::under($silence);

        return $log instanceof TestLog ? new self($silence->progress(), $log) : $log;
    }

    /** The test the run is running, where it has run past its own limit at these seconds of the clock. */
    public function overrun(float $now): Overrun|NotGiven
    {
        $this->log = $this->log->after($this->added(), $now);

        return $this->log->pastLimit($now);
    }

    private function added(): string
    {
        $added = is_file($this->file) ? (string) file_get_contents($this->file, offset: $this->read) : '';
        $this->read += Bytes::length($added);

        return $added;
    }
}
