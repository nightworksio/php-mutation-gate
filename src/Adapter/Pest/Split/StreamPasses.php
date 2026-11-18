<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function explode;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_string;

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
 *
 * @internal
 */
final readonly class StreamPasses
{
    /** The file beside a run's results its passes are listed in. */
    private const string LISTED = '%s.passes';

    /** No pass listed beside a run's results, as a run starts. */
    public static function cleared(string $results): void
    {
        $listed = sprintf(self::LISTED, $results);

        if (is_file($listed)) {
            unlink($listed);
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
        if (! is_string($results) || $results === '') {
            return;
        }

        $step = StepTime::of($pass, Seconds::none(), Seconds::of($seconds), $mutants)->bounded(Seconds::of($bound));
        $line = sprintf("%s\n", JsonText::compact(StepsRecord::rowOf($step)));
        file_put_contents(sprintf(self::LISTED, $results), $line, FILE_APPEND | LOCK_EX);
    }

    /** The passes listed beside a run's results, as steps within its mutation step; none where none ended. */
    public static function within(string $results, StepTime $mutation): StepTimes
    {
        $listed = sprintf(self::LISTED, $results);
        $text = is_file($listed) ? file_get_contents($listed) : false;
        $steps = [];
        $since = $mutation->since()->seconds();

        foreach (explode("\n", is_string($text) ? $text : '') as $line) {
            $step = self::stepOf(Node::decode($line), Seconds::of($since));
            $steps = $step instanceof StepTime ? [...$steps, $step] : $steps;
            $since += $step instanceof StepTime ? $step->took()->seconds() : 0.0;
        }

        return StepTimes::of(...$steps);
    }

    /** The pass a line lists, started this long after its shard began; none where the line lists none. */
    private static function stepOf(Node $line, Seconds $since): StepTime|false
    {
        try {
            $step = StepsRecord::stepIn($line);
        } catch (NotInShape) {
            return false;
        }

        return $step->step() === Step::FirstPass || $step->step() === Step::SecondPass ? $step->later($since) : false;
    }
}
