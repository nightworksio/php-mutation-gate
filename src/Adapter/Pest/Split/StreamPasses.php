<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_last;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function in_array;
use function is_file;
use function is_string;
use function max;

use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Format\JsonText;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Plan\StepsRecord;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;
use function unlink;

/**
 * The seconds each pass of a run's split streams took and the mutants it
 * forked, with the wall seconds it would take were every mutant the run
 * streams forked at each covering test (ADR-0196), each written beside the
 * run's results as a JSON line, a step as a result holds it, the
 * moment the pass ends, so a run stopped in its second pass keeps its
 * first's; read by the gate as steps within the run's mutation step, the
 * first pass from that step's start and the second from the first's end.
 * Each pass is also listed as it starts, so a pass the stop cut short is
 * read as running to the mutation step's end (ADR-0304).
 *
 * @internal
 */
final readonly class StreamPasses
{
    /** The file beside a run's results its passes are listed in. */
    private const string LISTED = '%s.passes';

    /** The steps a run lists: its passes, and the own runs of the mutants they left. */
    private const array LISTED_STEPS = [Step::FirstPass, Step::SecondPass, Step::OwnRuns];

    /** Where a row lists a pass as it starts. */
    private const string BEGUN = 'begun';

    /** No pass listed beside a run's results, as a run starts. */
    public static function cleared(string $results): void
    {
        $listed = sprintf(self::LISTED, $results);

        if (is_file($listed)) {
            unlink($listed);
        }
    }

    /** One pass as it starts, listed beside the run's results the gate names; none where it names no file. */
    public static function started(string|false $results, Step $pass, int $mutants, float $bound): void
    {
        $step = StepTime::of($pass, Seconds::none(), Seconds::none(), $mutants)->bounded(Seconds::of($bound));
        self::listed($results, JsonText::compact([...StepsRecord::rowOf($step), self::BEGUN => true]));
    }

    /** A run's own runs as they start, this many of them (ADR-0117), listed as a pass is; none where there is none. */
    public static function ownRunsStarted(string|false $results, int $mutants, float $bound): void
    {
        if ($mutants > 0) {
            self::started($results, Step::OwnRuns, $mutants, $bound);
        }
    }

    /** One pass, listed beside the run's results the gate names; none where it names no file. */
    public static function ended(
        string|false $results,
        Step $pass,
        float $seconds,
        int $mutants,
        float $bound,
    ): void {
        $step = StepTime::of($pass, Seconds::none(), Seconds::of($seconds), $mutants)->bounded(Seconds::of($bound));
        self::listed($results, JsonText::compact(StepsRecord::rowOf($step)));
    }

    /**
     * The passes listed beside a run's results, as steps within its mutation
     * step: each that ended as it ended, and one that started and never
     * ended as running to the mutation step's end; none where none started.
     */
    public static function within(string $results, StepTime $mutation): StepTimes
    {
        $rows = self::rowsOf($results, $mutation->since());
        $ended = [];

        foreach ($rows as [$step, $starts]) {
            $ended = $starts ? $ended : [...$ended, $step];
        }

        $last = array_last($rows);
        $end = $mutation->since()->seconds() + $mutation->took()->seconds();

        return StepTimes::of(...$last !== null && $last[1]
            ? [...$ended, self::cutShort($last[0], $end - $last[0]->since()->seconds())]
            : $ended);
    }

    /**
     * Each pass listed beside a run's results, each from where the one
     * before it ended, and whether it lists the pass as it starts.
     *
     * @return list<array{StepTime, bool}>
     */
    private static function rowsOf(string $results, Seconds $from): array
    {
        $listed = sprintf(self::LISTED, $results);
        $text = is_file($listed) ? file_get_contents($listed) : false;
        $rows = [];
        $since = $from->seconds();

        foreach (explode("\n", is_string($text) ? $text : '') as $line) {
            $node = Node::decode($line);
            $step = self::stepOf($node, Seconds::of($since));

            if ($step instanceof StepTime) {
                $rows[] = [$step, $node->field(self::BEGUN)->isPresent()];
                $since += $step->took()->seconds();
            }
        }

        return $rows;
    }

    /** A pass the stop cut short, as running this long. */
    private static function cutShort(StepTime $started, float $seconds): StepTime
    {
        $cut = StepTime::of($started->step(), $started->since(), Seconds::of(max(0.0, $seconds)), $started->count());
        $bound = $started->bound();

        return $bound instanceof Seconds ? $cut->bounded($bound) : $cut;
    }

    /** One line of JSON, listed beside the run's results the gate names; none where it names no file. */
    private static function listed(string|false $results, string $row): void
    {
        if (! is_string($results) || $results === '') {
            return;
        }

        file_put_contents(sprintf(self::LISTED, $results), sprintf("%s\n", $row), FILE_APPEND | LOCK_EX);
    }

    /** The pass a line lists, started this long after its shard began; none where the line lists none. */
    private static function stepOf(Node $line, Seconds $since): StepTime|false
    {
        try {
            $step = StepsRecord::stepIn($line);
        } catch (NotInShape) {
            return false;
        }

        return in_array($step->step(), self::LISTED_STEPS, strict: true) ? $step->later($since) : false;
    }
}
