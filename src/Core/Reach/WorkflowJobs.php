<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Reach;

use function array_any;
use function array_filter;
use function array_push;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;

/**
 * A GitHub Actions workflow that runs the gate, read job by job (ADR-0058):
 * its keys outside `jobs`, which every job shares, but for its `name`,
 * `run-name` and `concurrency`, which say what its runs are called and
 * whether one waits for another, and the jobs that run the
 * gate, each without the job's own `name`, `if` and `needs`, which say what
 * it is called, whether it runs and after what, and nothing of how it runs
 * the gate. Every other job is left out, so a change to it runs the gate as
 * before. A job runs the gate where it uses the gate's action or reusable
 * workflow, or a step runs the gate's `run`, `plan`, `verdict` or
 * `survivors`, or `composer mutate`, the script `init` adds. A definition
 * with no top-level `jobs`, a job its reader cannot bound, or none that runs
 * the gate, is read whole.
 *
 * @internal
 */
final readonly class WorkflowJobs
{
    /** The line that opens a workflow's jobs. */
    private const string JOBS = '/^jobs: *(#.*)?$/u';

    /** A job's first line, its id at the indentation of the jobs' map. */
    private const string JOB = '/^  [A-Za-z0-9_-]+: *(#.*)?$/u';

    /** A line at a job id's indentation or less that is no blank and no comment, which this reader cannot place. */
    private const string UNBOUND = '/^ {0,3}[^ #]/u';

    /** A line that holds nothing, or nothing but a comment. */
    private const string NOTHING = '/^ *(#.*)?$/u';

    /** A top-level key, which ends the jobs. */
    private const string TOP = '/^[^ #]/u';

    /** A job's own key that says what it is called, whether it runs or after what. */
    private const string OWN = '/^    (name|if|needs): */u';

    /** A line inside the key above it: deeper, or blank. */
    private const string INSIDE = '/^( {5,}| *$)/u';

    /** A workflow's own key that says what its runs are called or whether one waits for another. */
    private const string WORKFLOW_OWN = '/^(name|run-name|concurrency): */u';

    /** A line inside a top-level key: indented, or blank. */
    private const string INDENTED = '/^( +| *$)/u';

    /** A step or a job that uses the gate's action, or a job that calls its reusable workflow. */
    private const string USES = '/^ *(- )?uses: *[\'"]?[^\'"\s]*(php-mutation-gate|\/mutation-gate\.ya?ml)/u';

    /**
     * A command line that runs the gate to judge: run, plan, verdict or
     * survivors, its default run, or the script `init` adds.
     */
    private const string RUNS
        = '/(^|[\s\'"\/=])mutation-gate[\'"]?( +(run|plan|verdict|survivors)\b| *$| +-)|\bcomposer +mutate\b/u';

    /** The workflow as it runs the gate, or not given where it is read whole. */
    public static function running(string $workflow): string|NotGiven
    {
        $lines = explode("\n", $workflow);
        $start = self::jobsAt($lines);
        $gate = $start instanceof NotGiven ? $start : self::gateJobs($lines, $start);

        return $gate instanceof NotGiven ? $gate : self::joined($lines, ...$gate);
    }

    /**
     * Where the jobs open and end, and the jobs that run the gate; not given
     * where a job cannot be bounded or none runs the gate.
     *
     * @param  list<string>                                  $lines
     * @return array{int, int, list<list<string>>}|NotGiven
     */
    private static function gateJobs(array $lines, int $start): array|NotGiven
    {
        $end = self::endOf($lines, $start + 1);
        $jobs = self::jobsIn(array_slice($lines, $start + 1, $end - $start - 1));
        $gate = $jobs instanceof NotGiven ? [] : array_values(array_filter($jobs, self::runsTheGate(...)));

        return $gate === [] ? NotGiven::value() : [$start, $end, $gate];
    }

    /**
     * The lines up to the jobs, the gate's jobs without their own keys, and
     * the lines after the jobs.
     *
     * @param list<string>       $lines
     * @param list<list<string>> $gate
     */
    private static function joined(array $lines, int $start, int $end, array $gate): string
    {
        $kept = self::without(array_slice($lines, 0, $start + 1), self::WORKFLOW_OWN, self::INDENTED);

        foreach ($gate as $job) {
            array_push($kept, ...self::without($job, self::OWN, self::INSIDE));
        }

        array_push($kept, ...self::without(array_slice($lines, $end), self::WORKFLOW_OWN, self::INDENTED));

        return implode("\n", $kept);
    }

    /**
     * Where the jobs open, or not given where the workflow has no top-level `jobs`.
     *
     * @param list<string> $lines
     */
    private static function jobsAt(array $lines): int|NotGiven
    {
        foreach ($lines as $at => $line) {
            if (preg_match(self::JOBS, $line) === 1) {
                return $at;
            }
        }

        return NotGiven::value();
    }

    /**
     * Where the jobs end: the next top-level key, or the end of the workflow.
     *
     * @param list<string> $lines
     */
    private static function endOf(array $lines, int $from): int
    {
        $count = count($lines);

        for ($at = $from; $at < $count; ++$at) {
            if (preg_match(self::TOP, $lines[$at]) === 1) {
                return $at;
            }
        }

        return count($lines);
    }

    /**
     * Each job, as its lines from its id on, or not given where a line before
     * the first job, or between jobs, is one this reader cannot place.
     *
     * @param  list<string>                $lines
     * @return list<list<string>>|NotGiven
     */
    private static function jobsIn(array $lines): array|NotGiven
    {
        $jobs = [];

        foreach ($lines as $line) {
            if (preg_match(self::JOB, $line) === 1) {
                $jobs[] = [$line];

                continue;
            }

            if (! self::placed($line, inJob: $jobs !== [])) {
                return NotGiven::value();
            }

            $jobs = self::appended($jobs, $line);
        }

        return $jobs;
    }

    /** Whether a line that opens no job is one this reader can place: in a job, or blank or a comment before one. */
    private static function placed(string $line, bool $inJob): bool
    {
        return $inJob ? preg_match(self::UNBOUND, $line) !== 1 : preg_match(self::NOTHING, $line) === 1;
    }

    /**
     * These jobs, with a line added to the last; as they are where there is none yet.
     *
     * @param  list<list<string>> $jobs
     * @return list<list<string>>
     */
    private static function appended(array $jobs, string $line): array
    {
        if ($jobs !== []) {
            $jobs[count($jobs) - 1][] = $line;
        }

        return $jobs;
    }

    /** @param list<string> $job */
    private static function runsTheGate(array $job): bool
    {
        return array_any(
            $job,
            static fn(string $line): bool => preg_match(AsItRuns::COMMENT, $line) !== 1
                && (preg_match(self::USES, $line) === 1 || preg_match(self::RUNS, $line) === 1),
        );
    }

    /**
     * These lines without each key the first pattern opens, nor the lines
     * the second takes for inside it.
     *
     * @param  list<string> $lines
     * @return list<string>
     */
    private static function without(array $lines, string $key, string $inside): array
    {
        $kept = [];
        $skipping = false;

        foreach ($lines as $line) {
            $skipping = preg_match($key, $line) === 1 || ($skipping && preg_match($inside, $line) === 1);

            if (! $skipping) {
                $kept[] = $line;
            }
        }

        return $kept;
    }
}
