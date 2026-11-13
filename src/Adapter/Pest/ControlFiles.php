<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function file_get_contents;
use function is_file;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Control\Control;
use NightWorksIO\MutationGate\Core\Control\ControlRun;
use NightWorksIO\MutationGate\Core\Control\PeakLauncher;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Test\JUnitFailures;

use function sprintf;

/**
 * The files an unmutated control at a position is run with: its file served
 * unmutated, or as the mutant a part of a heavy mutant's run serves, the
 * peak its launcher writes, its JUnit log, where it writes the events of its
 * tests as they happen, and its forked child's peak (see UnmutatedRuns).
 *
 * @internal
 */
final readonly class ControlFiles
{
    /** Where the launcher of a control at a position writes its peak, among the adapter's own files. */
    private const string PEAK = 'pest/controls/peak-%d';

    /** Where the client of a control at a position that forked writes its child's peak (see TailShell). */
    private const string FORKED_PEAK = 'pest/controls/forked-peak-%d';

    /** Where a control at a position writes its JUnit log, which names the tests that failed in it. */
    private const string LOG = 'pest/controls/junit-%d.xml';

    /** Where a control at a position writes the events of its tests as they happen, where it has a silence limit. */
    private const string EVENTS = 'pest/controls/events-%d.txt';

    private function __construct(
        private ServedOriginal $served,
        private string $peak,
        private string $log,
        private string $events,
        private string $forked,
    ) {
    }

    /** The files of the control at a position, its copy written in this directory; or why one cannot be. */
    public static function at(Project $project, int $at, Control $control, string $directory): self|CannotJudge
    {
        $text = $control->served();
        $served = $text instanceof Contents
            ? ServedOriginal::as($project, $directory, $control->file(), $text)
            : ServedOriginal::of($project, $directory, $control->file());
        $peak = $served instanceof CannotJudge ? $served : $project->fresh(sprintf(self::PEAK, $at));
        $log = $peak instanceof CannotJudge ? $peak : $project->fresh(sprintf(self::LOG, $at));
        $events = $log instanceof CannotJudge ? $log : $project->fresh(sprintf(self::EVENTS, $at));
        $forked = $events instanceof CannotJudge ? $events : $project->fresh(sprintf(self::FORKED_PEAK, $at));

        return match (true) {
            $served instanceof CannotJudge => $served,
            $peak instanceof CannotJudge => $peak,
            $log instanceof CannotJudge => $log,
            $events instanceof CannotJudge => $events,
            $forked instanceof CannotJudge => $forked,
            default => new self($served, $peak, $log, $events, $forked),
        };
    }

    public function served(): ServedOriginal
    {
        return $this->served;
    }

    public function peak(): string
    {
        return $this->peak;
    }

    public function log(): string
    {
        return $this->log;
    }

    public function events(): string
    {
        return $this->events;
    }

    public function forked(): string
    {
        return $this->forked;
    }

    /** What the control found as its process ended: the tests its log names failing, at the larger of both peaks. */
    public function found(Ran $ran): ControlRun
    {
        $failing = JUnitFailures::of(is_file($this->log) ? file_get_contents($this->log) : false);

        return ControlRun::ofProcess($ran, $failing)
            ->peakingAtLeast($this->peakIn($this->peak))
            ->peakingAtLeast($this->peakIn($this->forked));
    }

    /** The peak a file holds, as a launcher or a forked child's client writes it; none where it holds none. */
    private function peakIn(string $file): MemoryCap|NotGiven
    {
        return is_file($file)
            ? PeakLauncher::peakIn((string) file_get_contents($file), PHP_OS_FAMILY)
            : NotGiven::value();
    }
}
