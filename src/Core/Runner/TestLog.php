<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use function array_pop;
use function explode;
use function mb_strlen;
use function mb_strrpos;
use function mb_substr;

use NightWorksIO\MutationGate\Core\Native\Outcome;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Test\TestId;

use function rawurldecode;
use function sprintf;
use function str_contains;
use function str_starts_with;

/**
 * What a run's file of test events has told so far, read as it grows
 * (ADR-0090): the test it is running and since when, and the end of a line
 * not yet written whole.
 *
 * @internal
 */
final readonly class TestLog
{
    /** What starts a test in PHPUnit's events in text, its id in brackets after it. */
    private const string PREPARING = 'Test Preparation Started (';

    /** What ends a test in PHPUnit's events in text. */
    private const string FINISHED = 'Test Finished (';

    /** What closes a test's id in PHPUnit's events in text. */
    private const string CLOSING = ')';

    private function __construct(
        private TestEvents $events,
        private TestWatch $watch,
        private TestClock $clock,
        private string $pending,
    ) {
    }

    /** A log in this form that has told nothing yet, its tests held to this watch. */
    public static function of(TestEvents $events, TestWatch $watch): self
    {
        return new self($events, $watch, TestClock::idle(), '');
    }

    /** What the progress file of a process under this silence limit has told; none where it watches no test. */
    public static function under(SilenceLimit $silence): self|NotGiven
    {
        $watch = $silence->tests();

        return $watch instanceof TestWatch ? self::of($silence->events(), $watch) : $watch;
    }

    /** This log, after these bytes were added to it, read at these seconds of the clock. */
    public function after(string $added, float $at): self
    {
        $lines = explode("\n", sprintf('%s%s', $this->pending, $added));
        $pending = array_pop($lines);
        $clock = $this->clock;

        foreach ($lines as $line) {
            $started = $this->startedIn($line);
            $clock = match (true) {
                $started instanceof TestId => $clock->started($started, $at),
                $this->ends($line) => $clock->finished(),
                default => $clock,
            };
        }

        return new self($this->events, $this->watch, $clock, $pending);
    }

    /** The test running and the limit it has run past at these seconds of the clock; none where it has not. */
    public function pastLimit(float $now): Overrun|NotGiven
    {
        return $this->clock->pastLimit($this->watch, $now);
    }

    /** The test a line starts; none where it starts none. */
    private function startedIn(string $line): TestId|NotGiven
    {
        return match ($this->events) {
            TestEvents::PhpUnitText => str_starts_with($line, self::PREPARING)
                ? TestId::of(
                    mb_substr($line, mb_strlen(self::PREPARING), $this->closing($line) - mb_strlen(self::PREPARING)),
                )
                : NotGiven::value(),
            TestEvents::NativeResults => $this->recordedStart($line),
            TestEvents::Heartbeats => $this->marked($line),
        };
    }

    /** Whether a line ends the test running. */
    private function ends(string $line): bool
    {
        return match ($this->events) {
            TestEvents::PhpUnitText => str_starts_with($line, self::FINISHED),
            TestEvents::NativeResults => $line !== '',
            TestEvents::Heartbeats => str_contains($line, TestEvents::BEAT),
        };
    }

    /** The test a line of the extension's records starts; none where it starts none. */
    private function recordedStart(string $line): TestId|NotGiven
    {
        $started = sprintf('%s%s', Outcome::Started->value, Outcome::SEPARATOR);

        return str_starts_with($line, $started)
            ? TestId::of(rawurldecode(mb_substr($line, mb_strlen($started))))
            : NotGiven::value();
    }

    /** The test whose start a line of beats marks last; none where it marks none. */
    private function marked(string $line): TestId|NotGiven
    {
        $marked = mb_strrpos($line, TestEvents::TEST_STARTED);

        return $marked === false
            ? NotGiven::value()
            : TestId::of(rawurldecode(mb_substr($line, $marked + mb_strlen(TestEvents::TEST_STARTED))));
    }

    /** Where the bracket that closes a line's id is: its last. */
    private function closing(string $line): int
    {
        $closing = mb_strrpos($line, self::CLOSING);

        return $closing === false ? mb_strlen($line) : $closing;
    }
}
