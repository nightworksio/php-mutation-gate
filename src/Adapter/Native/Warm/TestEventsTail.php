<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Native\Warm;

use function fclose;
use function fopen;
use function fseek;
use function is_file;
use function is_resource;

use NightWorksIO\MutationGate\Core\Format\Bytes;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Overrun;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\TestLog;

use function sprintf;
use function stream_get_contents;

/**
 * A child's results file, read as it grows, for the watchdog that holds
 * each of its tests to a limit of its own (ADR-0090); none where the
 * child's silence limit watches no test.
 *
 * @internal
 */
final class TestEventsTail
{
    /** How many bytes of the file have been read. */
    private int $read = 0;

    private function __construct(private readonly string $file, private TestLog|NotGiven $log)
    {
    }

    /** The results file a child under this silence limit writes, where the limit watches its tests. */
    public static function of(SilenceLimit|NotGiven $silence): self
    {
        return $silence instanceof SilenceLimit
            ? new self($silence->progress(), TestLog::under($silence))
            : new self('', NotGiven::value());
    }

    /** The test the child is running, where it has run past its own limit at these seconds of the clock. */
    public function overrun(float $now): Overrun|NotGiven
    {
        if (! $this->log instanceof TestLog) {
            return NotGiven::value();
        }

        $this->log = $this->log->after($this->added(), $now);

        return $this->log->pastLimit($now);
    }

    private function added(): string
    {
        $handle = is_file($this->file) ? fopen($this->file, 'rb') : false;

        if (! is_resource($handle)) {
            return '';
        }

        fseek($handle, $this->read);
        $added = sprintf('%s', stream_get_contents($handle));
        fclose($handle);
        $this->read += Bytes::length($added);

        return $added;
    }
}
