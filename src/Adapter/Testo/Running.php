<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Testo;

use function file_put_contents;
use function hrtime;

use NightWorksIO\MutationGate\Core\Native\Outcome;
use NightWorksIO\MutationGate\Core\Native\Selected;
use NightWorksIO\MutationGate\Core\Native\TestTimes;
use NightWorksIO\MutationGate\Core\NotGiven;
use Testo\Core\Context\TestInfo;
use Testo\Core\Context\TestResult;
use Testo\Core\Value\Status;
use Testo\Core\Value\Summary;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestRunInterceptor;

/**
 * Runs each test the run selects, and none other, ahead of everything that
 * prepares or repeats a test, so one data set never stands for its test: a
 * test the run does not select is skipped before any of it runs, and once
 * one has failed or errored in a run that stops, every later one is
 * cancelled. It appends to the results file each test it runs as it starts
 * and how it ended, and, where it is told one, its time to the times file.
 * A listing runs none, and lists each instead.
 */
#[InterceptorOptions(order: InterceptorOptions::ORDER_FILTER + 1)]
final class Running implements TestRunInterceptor
{
    private const float NANOSECONDS = 1e9;

    private bool $failed = false;

    public function __construct(
        private readonly Selected $selected,
        private readonly bool $stops,
        private readonly string|NotGiven $results,
        private readonly string|NotGiven $times,
        private readonly Listing|NotGiven $listing,
    ) {
    }

    public function runTest(TestInfo $info, callable $next): TestResult
    {
        $test = TestoTest::of($info);

        if ($this->listing instanceof Listing) {
            $this->listing->add($test);
        }

        return match (true) {
            ! $this->selected->selects($test) => $this->without($info, Status::Skipped),
            $this->failed && $this->stops => $this->without($info, Status::Cancelled),
            default => $this->ran($info, $next, $test->key()),
        };
    }

    /** @param callable(TestInfo): TestResult $next */
    private function ran(TestInfo $info, callable $next, string $key): TestResult
    {
        $this->write($this->results, Outcome::Started->line($key));
        $from = hrtime(as_number: true);
        $result = $next($info);
        $seconds = (hrtime(as_number: true) - $from) / self::NANOSECONDS;
        $outcome = $this->outcomeOf($result->status);
        $this->write($this->results, $outcome->line($key));
        $this->write($this->times, TestTimes::line($key, $seconds));
        $this->failed = $this->failed || $outcome->kills();

        return $result;
    }

    /** How a test Testo gave this status ended: passed, failed, errored, which an aborted test is, or neither. */
    private function outcomeOf(Status $status): Outcome
    {
        return match ($status) {
            Status::Passed, Status::Flaky => Outcome::Passed,
            Status::Failed => Outcome::Failed,
            Status::Error, Status::Aborted => Outcome::Errored,
            Status::Skipped, Status::Risky, Status::Cancelled => Outcome::Neither,
        };
    }

    /** A test's result without running any of it. */
    private function without(TestInfo $info, Status $status): TestResult
    {
        return new TestResult(info: $info, status: $status, summary: Summary::forTest($status));
    }

    private function write(string|NotGiven $file, string $line): void
    {
        if (! $file instanceof NotGiven) {
            file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        }
    }
}
