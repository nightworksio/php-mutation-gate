<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use Closure;

use const FILE_APPEND;

use function file_put_contents;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Overrun;
use NightWorksIO\MutationGate\Core\Runner\PerTestLimit;
use NightWorksIO\MutationGate\Core\Runner\SilenceLimit;
use NightWorksIO\MutationGate\Core\Runner\TestEvents;
use NightWorksIO\MutationGate\Core\Runner\TestWatch;
use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestTime;
use NightWorksIO\MutationGate\Core\Test\TestTimes;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * A file of heartbeat test events a test grows, and what a reader of it,
 * as each adapter reads one, says has overrun its own limit as it grows:
 * `Money::adds` timed at 1 s, so held to the standard 3 s floor.
 */
final readonly class GrowingEvents
{
    public const string TEST = 'Money::adds';

    private function __construct(public string $file)
    {
    }

    public static function at(string $directory): self
    {
        return new self(sprintf('%s/events.txt', $directory));
    }

    /** The silence limit of a run that writes this file and watches `Money::adds`. */
    public function watched(): SilenceLimit
    {
        $watch = TestWatch::of(PerTestLimit::standard(), TestTimes::of(TestTime::of(TestId::of(self::TEST), Seconds::of(1.0))));

        return SilenceLimit::of(Seconds::of(60.0), $this->file)->watchingTests($watch, TestEvents::Heartbeats);
    }

    public function grown(string $bytes): void
    {
        file_put_contents($this->file, $bytes, FILE_APPEND);
    }

    /** The line that starts a test. */
    public static function started(string $test): string
    {
        return sprintf('%s%s', TestEvents::TEST_STARTED, $test);
    }

    /**
     * What a reader says at each of these seconds, the file grown by each
     * of these bytes just before: the test overrun, or `-` where none is.
     *
     * @param Closure(float): (Overrun|NotGiven) $overrun the reader's check
     * @param list<array{string, float}>        $steps   the bytes and the seconds
     * @return list<string>
     */
    public function read(Closure $overrun, array $steps): array
    {
        $said = [];

        foreach ($steps as [$bytes, $at]) {
            $this->grown($bytes);
            $found = $overrun($at);
            $said[] = $found instanceof Overrun ? $found->test()->value() : '-';
        }

        return $said;
    }
}
