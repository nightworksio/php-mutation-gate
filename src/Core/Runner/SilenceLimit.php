<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

/**
 * How long a process may go without progress before it is stopped with
 * every process it started, and the file whose growth is its progress, such
 * as a mutant's results file a line is added to as each test starts and
 * ends. The silence is counted from the file's last growth, so what the
 * process does before the file first grows is held only to its deadline
 * (ADR-0008). Each test the file says started can be held to a limit of its
 * own as well, or alone (ADR-0090).
 */
final readonly class SilenceLimit
{
    private function __construct(
        private Seconds|NotGiven $limit,
        private string $progress,
        private TestWatch|NotGiven $tests = new NotGiven(),
        private TestEvents $events = TestEvents::PhpUnitText,
    ) {
    }

    public static function of(Seconds $limit, string $progress): self
    {
        return new self($limit, $progress);
    }

    /** No silence limit on a process whose progress is this file, until its tests are watched. */
    public static function none(string $progress): self
    {
        return new self(NotGiven::value(), $progress);
    }

    /**
     * What stops a process before its deadline, its progress this file,
     * which tells its tests' starts and ends in this form: this silence
     * limit, and each test's own under this watch; none where neither does.
     */
    public static function stopping(
        Seconds|NotGiven $limit,
        TestWatch|NotGiven $tests,
        string $progress,
        TestEvents $events,
    ): self|NotGiven {
        $silence = new self($limit, $progress);

        return match (true) {
            $tests instanceof TestWatch && $tests->isOn() => $silence->watchingTests($tests, $events),
            $limit instanceof Seconds => $silence,
            default => NotGiven::value(),
        };
    }

    /**
     * This limit, each test the progress file says started held to its own
     * limit under this watch as well, the file's lines in this form
     * (ADR-0090); as it was where the watch holds no test.
     */
    public function watchingTests(TestWatch $tests, TestEvents $events): self
    {
        return $tests->isOn() ? new self($this->limit, $this->progress, $tests, $events) : $this;
    }

    /** How long the process may go without progress; none where only its tests are watched. */
    public function limit(): Seconds|NotGiven
    {
        return $this->limit;
    }

    /** The watch its tests are held to; none where none is. */
    public function tests(): TestWatch|NotGiven
    {
        return $this->tests;
    }

    /** The form the file tells its tests' starts and ends in. */
    public function events(): TestEvents
    {
        return $this->events;
    }

    /** The file whose growth is the process's progress. */
    public function progress(): string
    {
        return $this->progress;
    }
}
