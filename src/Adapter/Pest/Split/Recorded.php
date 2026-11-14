<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_unique;
use function array_values;
use function function_exists;
use function getenv;
use function ini_get;
use function is_string;
use function mb_strtolower;

use NightWorksIO\MutationGate\Adapter\Pest\ServedClasses;
use NightWorksIO\MutationGate\Core\Runner\OnSwitch;

use function outbreak_lines_start;
use function outbreak_lines_stop;
use function sort;

/**
 * The lines a stream's parent executes in one test, recorded by
 * `outbreak.so` where the stream observes `lines` (ADR-0147), and collected
 * by pcov where it is loaded otherwise, for a run that plans without a fresh
 * map (ADR-0135) or a quick round of `watch`: the streams record what the
 * coverage run would have measured, each schema served in a file's place
 * recorded as that file, whose lines it keeps. A run that stands in for the
 * suite's coverage report never observes `lines`, so pcov reports its
 * executable lines.
 *
 * @internal
 */
final readonly class Recorded
{
    /** The variable pcov reads before its `pcov.enabled`. */
    public const string ENABLED = 'PCOV_ENABLED';

    /** pcov's mark of a line a test executed. */
    private const int EXECUTED = 1;

    public function __construct(private LineCollector|false $collector)
    {
    }

    /** What records a test's lines in this process: `outbreak.so` where it can, else pcov where it is usable. */
    public static function inProcess(): self
    {
        return new self(self::usable() ? new PcovLines() : false);
    }

    /**
     * Whether pcov is loaded and enabled, so a test's lines can be collected:
     * `PCOV_ENABLED` wins over `pcov.enabled`, as pcov reads them.
     */
    public static function usable(): bool
    {
        $variable = getenv(self::ENABLED);
        $enabled = is_string($variable) ? $variable : ini_get('pcov.enabled');

        return function_exists('pcov\\start')
            && is_string($enabled)
            && OnSwitch::tryFrom(mb_strtolower($enabled)) instanceof OnSwitch;
    }

    /** Starts recording a test's lines afresh: what records them, or false where nothing can. */
    public function start(): LineRecorder|false
    {
        if (function_exists('outbreak_lines_start') && outbreak_lines_start()) {
            return LineRecorder::Outbreak;
        }

        if ($this->collector === false) {
            return false;
        }

        $this->collector->clear();
        $this->collector->start();

        return LineRecorder::Pcov;
    }

    /**
     * Stops recording: the lines of each file the test executed, and, where
     * pcov collected them, every line it reports in each file it loaded that
     * is not known yet, executed or not, which a coverage report counts as
     * executable.
     *
     * @param  array<string, list<int>>                                 $known
     * @return array{array<string, list<int>>, array<string, list<int>>}
     */
    public function stop(LineRecorder $recorder, array $known = []): array
    {
        if ($recorder === LineRecorder::Outbreak) {
            return [self::atOriginals(function_exists('outbreak_lines_stop') ? outbreak_lines_stop() : []), []];
        }

        return $this->collector === false ? [[], []] : $this->collected($this->collector, $known);
    }

    /**
     * Lines recorded by the file PHP compiled, each copy this process serves
     * taken as its original's, joined to any of the original's own.
     *
     * @param  array<string, list<int>> $recorded
     * @return array<string, list<int>>
     */
    public static function atOriginals(array $recorded): array
    {
        $lines = [];

        foreach ($recorded as $served => $executed) {
            $lines = self::joined($lines, ServedClasses::originalOf($served), $executed);
        }

        return $lines;
    }

    /**
     * @param  array<string, list<int>>                                 $known
     * @return array{array<string, list<int>>, array<string, list<int>>}
     */
    private function collected(LineCollector $collector, array $known): array
    {
        $collector->stop();
        $executed = [];
        $reported = [];

        foreach ($collector->collect() as $file => $marks) {
            $lines = $this->executedOf($marks);

            if ($lines !== []) {
                $executed[$file] = $lines;
            }

            if (! array_key_exists(ServedClasses::originalOf($file), $known)) {
                $reported[$file] = $this->reportedOf($marks);
            }
        }

        return [self::atOriginals($executed), self::atOriginals($reported)];
    }

    /**
     * These lines, with more of a file's joined to those they hold of it.
     *
     * @param  array<string, list<int>> $lines
     * @param  list<int>                $more
     * @return array<string, list<int>>
     */
    private static function joined(array $lines, string $file, array $more): array
    {
        $joined = array_values(array_unique([...array_key_exists($file, $lines) ? $lines[$file] : [], ...$more]));
        sort($joined);
        $lines[$file] = $joined;

        return $lines;
    }

    /**
     * @param  array<int, int> $marks
     * @return list<int>
     */
    private function executedOf(array $marks): array
    {
        $lines = array_keys(array_filter($marks, static fn(int $mark): bool => $mark === self::EXECUTED));
        sort($lines);

        return $lines;
    }

    /**
     * @param  array<int, int> $marks
     * @return list<int>
     */
    private function reportedOf(array $marks): array
    {
        $lines = array_keys($marks);
        sort($lines);

        return $lines;
    }
}
