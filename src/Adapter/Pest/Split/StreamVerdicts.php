<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_any;
use function array_filter;
use function array_key_exists;

use Closure;

use function count;
use function explode;
use function file_get_contents;
use function is_array;
use function is_file;
use function json_decode;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\KillerFile;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Placed;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordEvent;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Support\MutationTestResult;

use function sprintf;

/**
 * What a run's split streams found of each mutant (ADR-0117), read from
 * their results: a test a child failed or ended in, and the parent passed,
 * kills its mutant; a child its limit stopped in a test the parent passed
 * times it out; a mutant every child of passed every covering test the
 * stream reached it in, and whose covering tests the parent all passed,
 * survives. A mutant a covering test of did not run, failed in the parent or
 * forked nothing in, or whose file a stream loaded before serving its schema,
 * is left for its own run.
 *
 * @internal
 */
final class StreamVerdicts
{
    /** @var array<string, bool> whether the parent passed each test, by its id */
    private array $passed = [];

    /** @var array<string, true> the tests a stream forked nothing in */
    private array $fallback = [];

    /** @var array<string, true> the mutants a stream loaded the file of before serving its schema */
    private array $unserved = [];

    /** @var array<string, array<string, ChildEnd>> how each child came out of each test, by mutant and test */
    private array $children = [];

    /** @var array<string, array<string, true>> each test a child touched nondeterminism in, by mutant */
    private array $touched = [];

    /**
     * @var array<string, array{0?: string, 1?: string}> the digest of the state each fork's call left, in the
     *                                                   parent then the child, by mutant and test (H5)
     */
    private array $states = [];

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
                $this->take(StreamRead::of($read));
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
        $decided = static fn(ChildEnd $end): bool => $end !== ChildEnd::Passed;

        return $this->firstEnding($mutant, $covering, $decided) !== '';
    }

    /**
     * Gives a mutant its result, where the streams judged it, and announces
     * it as its own run's would be; leaves it untouched otherwise.
     *
     * @param list<string> $covering the tests that cover it, by their ids
     */
    public function give(string $mutant, MutationTest $test, array $covering): void
    {
        $events = Facade::instance();
        $killer = $this->firstEnding($mutant, $covering, static fn(ChildEnd $end): bool => $end->kills());

        if ($killer !== '' && array_key_exists($killer, $this->touched[$mutant] ?? [])) {
            return;
        }

        if ($killer !== '') {
            $this->note($test, KillerFile::line(RecordEvent::Killed, $killer, Placed::unplaced(0)));
            $test->updateResult(MutationTestResult::Tested);
            $events->emitter()->mutationTested($test);

            return;
        }

        $overran = static fn(ChildEnd $end): bool => $end === ChildEnd::TimedOut;
        $timedOut = $this->firstEnding($mutant, $covering, $overran);

        if ($timedOut !== '') {
            $this->note($test, '');
            $test->updateResult(MutationTestResult::Timeout);
            $events->emitter()->mutationTimedOut($test);

            return;
        }

        if (! $this->leavesForItsOwnRun($mutant, $covering)) {
            $this->note($test, '');
            $test->updateResult(MutationTestResult::Untested);
            $events->emitter()->mutationEscaped($test);
        }
    }

    /**
     * Gives a mutant no test executed in a run planned without a fresh map
     * its result: uncovered, announced as Pest's own run would (ADR-0135).
     */
    public function uncovered(MutationTest $test): void
    {
        $this->note($test, '');
        $test->updateResult(MutationTestResult::Uncovered);
        Facade::instance()->emitter()->mutationUncovered($test);
    }

    /**
     * How many forks read their state in parent and child, and how many of
     * those found it alike (H5).
     *
     * @return array{int, int}
     */
    public function forkStates(): array
    {
        $read = array_filter($this->states, static fn(array $both): bool => count($both) === 2);
        $alike = array_filter($read, static fn(array $both): bool => ($both[0] ?? '') === ($both[1] ?? ''));

        return [count($read), count($alike)];
    }

    /**
     * The first covering test the parent passed in which a child of the
     * mutant came out as asked; '' where none did.
     *
     * @param list<string>             $covering
     * @param Closure(ChildEnd): bool $asked
     */
    private function firstEnding(string $mutant, array $covering, Closure $asked): string
    {
        foreach ($covering as $covered) {
            if ($this->passes($covered) && $asked($this->endOf($mutant, $covered))) {
                return $covered;
            }
        }

        return '';
    }

    /** @param list<string> $covering */
    private function leavesForItsOwnRun(string $mutant, array $covering): bool
    {
        if (array_key_exists($mutant, $this->unserved)) {
            return true;
        }

        return array_any(
            $covering,
            fn(string $covered): bool => ! $this->passes($covered) || array_key_exists($covered, $this->fallback),
        );
    }

    private function passes(string $test): bool
    {
        return array_key_exists($test, $this->passed) && $this->passed[$test];
    }

    private function endOf(string $mutant, string $test): ChildEnd
    {
        return array_key_exists($mutant, $this->children) && array_key_exists($test, $this->children[$mutant])
            ? $this->children[$mutant][$test]
            : ChildEnd::Passed;
    }

    private function take(StreamRead $read): void
    {
        $end = $read->childEnd();

        if ($read->kind === StreamLine::PARENT) {
            $earlier = ! array_key_exists($read->test, $this->passed) || $this->passed[$read->test];
            $this->passed[$read->test] = $read->passed && $earlier;
        }

        if ($read->kind === StreamLine::FALLBACK) {
            $this->fallback[$read->test] = true;
        }

        if ($read->kind === StreamLine::UNSERVED) {
            $this->unserved[$read->mutant] = true;
        }

        if ($end instanceof ChildEnd) {
            $this->children[$read->mutant][$read->test] = $end;
        }

        if ($read->touched) {
            $this->touched[$read->mutant][$read->test] = true;
        }

        if ($read->kind === StreamLine::STATE) {
            $this->states[sprintf('%s %s', $read->mutant, $read->test)][$read->child ? 1 : 0] = $read->digest;
        }
    }

    /** Writes that a stream judged the mutant, and this line, to its killer file, which Pest's own process takes. */
    private function note(MutationTest $test, string $line): void
    {
        Noted::of($test, sprintf('%s%s', KillerFile::streamed(), $line));
    }
}
