<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_key_exists;
use function explode;

use const FILE_APPEND;

use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_bool;
use function is_file;
use function is_string;
use function json_decode;

use const LOCK_EX;

use NightWorksIO\MutationGate\Adapter\Pest\GateVariable;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Placed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Support\MutationTestResult;

/**
 * What a run's split streams found of each mutant (ADR-0117), read from
 * their results: a test a child failed or ended in, and the parent passed,
 * kills its mutant; a child its limit stopped in a test the parent passed
 * times it out; a mutant every child of passed every covering test the
 * stream reached it in, and whose covering tests the parent all passed,
 * survives. A mutant a covering test of did not run, fail in the parent, or
 * fork nothing in, is left for its own run.
 *
 * @internal
 */
final class StreamVerdicts
{
    /** @var array<string, bool> whether the parent passed each test, by its id */
    private array $passed = [];

    /** @var array<string, true> the tests a stream forked nothing in */
    private array $fallback = [];

    /** @var array<string, array<string, ChildEnd>> how each child came out of each test, by mutant and test */
    private array $children = [];

    public static function none(): self
    {
        return new self();
    }

    /** These verdicts, with what one stream's results say. */
    public function reading(string $results): self
    {
        $text = is_file($results) ? (string) file_get_contents($results) : '';

        foreach (explode("\n", $text) as $line) {
            $read = json_decode($line, associative: true);

            if (is_array($read)) {
                $this->take($read);
            }
        }

        return $this;
    }

    /**
     * Whether a test the parent passed, and a child of the mutant failed,
     * ended or overran, already decides it as killed or timed out.
     *
     * @param list<string> $covering the tests that cover it, by their ids
     */
    public function decides(string $mutant, array $covering): bool
    {
        foreach ($covering as $covered) {
            $ended = $this->children[$mutant][$covered] ?? ChildEnd::Passed;

            if (($this->passed[$covered] ?? false) && $ended !== ChildEnd::Passed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gives a mutant its result, where the streams judged it, and announces
     * it as its own run's would be; leaves it untouched otherwise.
     *
     * @param list<string> $covering the tests that cover it, by their ids
     */
    public function give(string $mutant, MutationTest $test, array $covering, Facade $events): void
    {
        $outcome = StreamLine::CHILD;

        foreach ($covering as $covered) {
            $ended = $this->children[$mutant][$covered] ?? ChildEnd::Passed;

            if (($this->passed[$covered] ?? false) && $ended->kills()) {
                $this->killedBy($test, $covered, $events);

                return;
            }

            if (($this->passed[$covered] ?? false) && $ended === ChildEnd::TimedOut) {
                $outcome = StreamLine::TIMEOUT;
            }

            if (! ($this->passed[$covered] ?? false) || array_key_exists($covered, $this->fallback)) {
                $outcome = $outcome === StreamLine::TIMEOUT ? $outcome : StreamLine::FALLBACK;
            }
        }

        match ($outcome) {
            StreamLine::TIMEOUT => $this->timedOut($test, $events),
            StreamLine::FALLBACK => null,
            default => $this->survived($test, $events),
        };
    }

    /** @param array<mixed> $read */
    private function take(array $read): void
    {
        $test = is_string($read['t'] ?? null) ? $read['t'] : '';
        $mutant = is_string($read['m'] ?? null) ? $read['m'] : '';

        match ($read[StreamLine::KIND] ?? null) {
            StreamLine::PARENT => $this->passed[$test] = is_bool($read['p'] ?? null) && $read['p'] && ($this->passed[$test] ?? true),
            StreamLine::FALLBACK => $this->fallback[$test] = true,
            StreamLine::CHILD => $this->children[$mutant][$test] = ($read['f'] ?? false) === true ? ChildEnd::Failed : ChildEnd::Passed,
            StreamLine::ENDED => $this->children[$mutant][$test] = ChildEnd::Ended,
            StreamLine::TIMEOUT => $this->children[$mutant][$test] = ChildEnd::TimedOut,
            default => null,
        };
    }

    private function killedBy(MutationTest $test, string $killer, Facade $events): void
    {
        $this->note($test, KillerFile::line(RecordEvent::Killed, $killer, Placed::unplaced(0)));
        $test->updateResult(MutationTestResult::Tested);
        $events->emitter()->mutationTested($test);
    }

    private function timedOut(MutationTest $test, Facade $events): void
    {
        $this->note($test, '');
        $test->updateResult(MutationTestResult::Timeout);
        $events->emitter()->mutationTimedOut($test);
    }

    private function survived(MutationTest $test, Facade $events): void
    {
        $this->note($test, '');
        $test->updateResult(MutationTestResult::Untested);
        $events->emitter()->mutationEscaped($test);
    }

    /** Writes that a stream judged the mutant, and this line, to its killer file, which Pest's own process takes. */
    private function note(MutationTest $test, string $line): void
    {
        $results = getenv(GateVariable::Results->value);

        if (is_string($results) && $results !== '') {
            file_put_contents(
                KillerFile::beside($results, $test->mutation->modifiedSourcePath),
                KillerFile::streamed() . $line,
                FILE_APPEND | LOCK_EX,
            );
        }
    }
}
