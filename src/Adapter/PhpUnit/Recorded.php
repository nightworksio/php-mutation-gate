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

use function rawurldecode;
use function str_contains;

/**
 * The tests the extension recorded for one mutant: which started, and how
 * each that finished ended. The tests that killed it are those that failed or
 * errored, and each that started and never finished, since its process died
 * as it ran.
 */
final readonly class Recorded
{
    private function __construct(
        private TestIds $started,
        private TestIds $finished,
        private TestIds $failed,
        private TestIds $passed,
    ) {
    }

    /** What a results file records; a file that is not there records nothing. */
    public static function in(string $results): self
    {
        $lines = is_file($results) ? file($results, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $recorded = new self(TestIds::none(), TestIds::none(), TestIds::none(), TestIds::none());

        foreach (is_array($lines) ? $lines : [] as $line) {
            [$mark, $test] = str_contains($line, Outcome::SEPARATOR)
                ? explode(Outcome::SEPARATOR, $line, 2)
                : [$line, ''];
            $outcome = Outcome::tryFrom($mark);
            $id = TestId::of(rawurldecode($test));
            $recorded = $outcome instanceof Outcome ? $recorded->with($outcome, $id) : $recorded;
        }

        return $recorded;
    }

    /** Every test that failed or errored, and every test that started and never finished. */
    public function killers(): TestIds
    {
        $killers = $this->failed;

        foreach ($this->started as $test) {
            $killers = $this->finished->has($test) ? $killers : $killers->with($test);
        }

        return $killers;
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

    private function with(Outcome $outcome, TestId $test): self
    {
        return $outcome === Outcome::Started
            ? new self($this->started->with($test), $this->finished, $this->failed, $this->passed)
            : new self(
                $this->started,
                $this->finished->with($test),
                $outcome->kills() ? $this->failed->with($test) : $this->failed,
                $outcome === Outcome::Passed ? $this->passed->with($test) : $this->passed,
            );
    }
}
