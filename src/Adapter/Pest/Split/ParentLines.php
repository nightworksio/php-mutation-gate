<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use const FILE_APPEND;

use function file_put_contents;

use const LOCK_EX;

use NightWorksIO\MutationGate\Core\Coverage\TestEnded;

/**
 * The lines each test of a split stream's parent executed, recorded where
 * the plan records them (ADR-0135, ADR-0147): a test that runs in a process
 * of its own is recorded as one, with none of its lines; and, once the run
 * ends, every line each file the parent's tests loaded holds.
 *
 * @internal
 */
final class ParentLines
{
    /** What records the lines of the current test, or false where nothing does. */
    private LineRecorder|false $recording = false;

    /** Whether the current test runs in a process of its own, where the parent collects none of its lines. */
    private bool $own = false;

    /** @var array<string, list<int>> each line pcov reported in each file the parent's tests loaded, executed or not */
    private array $reported = [];

    public function __construct(private readonly StreamPlan $plan, private readonly Recorded $recorded)
    {
    }

    /** A test begins: its lines recorded afresh where this process is the parent and the plan records. */
    public function began(bool $parent): void
    {
        $this->own = false;
        $this->recording = $parent && $this->plan->recording !== '' ? $this->recorded->start() : false;
    }

    /** The test runs in a process of its own. */
    public function isolated(string $test): void
    {
        $this->own = true;

        if ($this->recording !== false) {
            $this->write(StreamLine::covered(
                $test,
                [],
                new TestEnded(passed: false, troubled: false, own: true, seconds: 0.0),
            ));
        }
    }

    /** The test ended so: the lines it executed. */
    public function ended(string $test, bool $passed, bool $troubled, float $seconds): void
    {
        if ($this->recording === false) {
            return;
        }

        [$executed, $reported] = $this->recorded->stop($this->recording, $this->reported);
        $this->reported += $reported;
        $this->write(StreamLine::covered($test, $executed, new TestEnded($passed, $troubled, $this->own, $seconds)));
    }

    /** The run ended: every line each file the parent's tests loaded holds. */
    public function finished(): void
    {
        if ($this->plan->recording !== '') {
            $this->write(StreamLine::finished($this->reported));
        }
    }

    private function write(string $line): void
    {
        file_put_contents($this->plan->recording, $line, FILE_APPEND | LOCK_EX);
    }
}
