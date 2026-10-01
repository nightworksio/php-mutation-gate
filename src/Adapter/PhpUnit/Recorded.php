<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\PhpUnit;

use function count;
use function explode;
use function file;
use function is_array;
use function is_file;

use NightWorksIO\MutationGate\Core\Test\TestId;
use NightWorksIO\MutationGate\Core\Test\TestIds;
use NightWorksIO\MutationGate\Core\Test\TestMethod;

use function rawurldecode;
use function str_contains;

/**
 * The tests the extension recorded for one mutant: which started, and how
 * each test ended: finished, skipped or marked incomplete. A test the run
 * selected whose class's `setUpBeforeClass` failed or errored ended so too.
 * The tests that killed it are those that failed or errored, and each that
 * started and never ended, since its process died as it ran. The kill is
 * credited only to those of them the run selected.
 */
final readonly class Recorded
{
    private function __construct(
        private TestIds $selected,
        private TestIds $started,
        private TestIds $finished,
        private TestIds $failed,
        private TestIds $passed,
    ) {
    }

    /**
     * What a results file records of the tests a run selected; a file that is
     * not there records nothing. A test the run did not select, which ran as
     * it shares a file with one it did, kills where it fails, though the kill
     * is not credited to it, and ends as neither where it passes: its pass
     * says nothing of the mutant.
     */
    public static function in(string $results, TestIds $selected): self
    {
        $lines = is_file($results) ? file($results, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $recorded = new self($selected, TestIds::none(), TestIds::none(), TestIds::none(), TestIds::none());

        foreach (is_array($lines) ? $lines : [] as $line) {
            [$mark, $test] = str_contains($line, Outcome::SEPARATOR)
                ? explode(Outcome::SEPARATOR, $line, 2)
                : [$line, ''];
            $outcome = Outcome::tryFrom($mark);
            $id = TestId::of(rawurldecode($test));
            $recorded = match (true) {
                $outcome === Outcome::ClassFailed => $recorded->withClass($id->value()),
                $outcome === Outcome::Passed && ! $selected->has($id) => $recorded->with(Outcome::Neither, $id),
                $outcome instanceof Outcome => $recorded->with($outcome, $id),
                default => $recorded,
            };
        }

        return $recorded;
    }

    /** Every test that failed or errored, and every test that started and never ended. */
    public function killers(): TestIds
    {
        $killers = $this->failed;

        foreach ($this->started as $test) {
            $killers = $this->finished->has($test) ? $killers : $killers->with($test);
        }

        return $killers;
    }

    /**
     * The killers the run selected, to which the kill is credited. A test it
     * ran only as it shares a file with one it selected covers no line of the
     * mutant, so the kill matrix is not told it killed one.
     */
    public function credited(): TestIds
    {
        $credited = TestIds::none();

        foreach ($this->killers() as $test) {
            $credited = $this->selected->has($test) ? $credited->with($test) : $credited;
        }

        return $credited;
    }

    /** Whether any test started or finished. */
    public function ranAny(): bool
    {
        return count($this->started) + count($this->finished) > 0;
    }

    /** Whether every test that ran finished neither passed, failed nor errored, such as skipped. */
    public function skippedEach(): bool
    {
        return $this->ranAny() && count($this->killers()) === 0 && count($this->passed) === 0;
    }

    /** These, with each selected test of a class whose `setUpBeforeClass` failed or errored ended as errored. */
    private function withClass(string $class): self
    {
        $recorded = $this;

        foreach ($this->selected as $test) {
            $recorded = TestMethod::classOf($test) === $class ? $recorded->with(Outcome::Errored, $test) : $recorded;
        }

        return $recorded;
    }

    private function with(Outcome $outcome, TestId $test): self
    {
        return $outcome === Outcome::Started
            ? new self($this->selected, $this->started->with($test), $this->finished, $this->failed, $this->passed)
            : new self(
                $this->selected,
                $this->started,
                $this->finished->with($test),
                $outcome->kills() ? $this->failed->with($test) : $this->failed,
                $outcome === Outcome::Passed ? $this->passed->with($test) : $this->passed,
            );
    }
}
