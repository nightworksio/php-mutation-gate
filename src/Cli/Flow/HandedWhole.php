<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function is_int;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Adapter\Pest\Split\OwnEnd;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Forking;
use NightWorksIO\MutationGate\Core\Written;

use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;

use const SIGKILL;
use const SIGUSR2;

/**
 * The plan's whole map, with each test file's entry key where the run keeps
 * one, as the directory `run` reads it from holds it (see Handoff): written
 * beside what the plan readies next in a child forked for it (ADR-0169),
 * which ends by SIGUSR2 once the map is written and by SIGKILL otherwise, so
 * nothing the plan set to happen at its end runs in it; written by the plan
 * itself where the child did not or this PHP cannot fork.
 *
 * @internal
 */
final readonly class HandedWhole
{
    private function __construct(
        private Directory $project,
        private CoverageMap $map,
        private MeasuredAt|Unplaced $at,
        private EntryKeys|NotGiven $keys,
        private int $level,
        private int $child,
    ) {
    }

    /** The whole map's writing, begun in a child where this PHP can fork. */
    public static function begun(
        Directory $project,
        CoverageMap $map,
        MeasuredAt|Unplaced $at,
        EntryKeys|NotGiven $keys,
        int $level,
    ): self {
        $child = Forking::besideTheGate() ? pcntl_fork() : -1;
        $whole = new self($project, $map, $at, $keys, $level, $child);

        if ($child === 0) {
            $wrote = CannotJudge::because(CoverageMapFile::in(Workspace::coverage())->value());

            try {
                $wrote = $whole->written();
            } finally {
                OwnEnd::by($wrote instanceof Written ? SIGUSR2 : SIGKILL);
            }
        }

        return $whole;
    }

    /** This writing where a child began it, and otherwise the whole map written by the plan itself once asked for. */
    public static function of(
        self|NotGiven $begun,
        Directory $project,
        CoverageMap $map,
        MeasuredAt|Unplaced $at,
        EntryKeys|NotGiven $keys,
        int $level,
    ): self {
        return $begun instanceof self ? $begun : self::now($project, $map, $at, $keys, $level);
    }

    /** The whole map, written by the plan itself once it is asked for. */
    public static function now(
        Directory $project,
        CoverageMap $map,
        MeasuredAt|Unplaced $at,
        EntryKeys|NotGiven $keys,
        int $level,
    ): self {
        return new self($project, $map, $at, $keys, $level, -1);
    }

    /** What writing the whole map came to: the child's map, or the plan's own where no child wrote one. */
    public function left(): Written|CannotJudge
    {
        $file = CoverageMapFile::in(Workspace::coverage());

        return $this->childWrote() ? Written::to($file->value()) : $this->written();
    }

    /**
     * Why the plan hands no map at all, the child's end waited for and any
     * whole map it wrote removed; or why that map cannot be removed.
     */
    public function abandonedFor(CannotJudge $why): CannotJudge
    {
        $removed = $this->childWrote()
            ? $this->project->remove(CoverageMapFile::in(Workspace::coverage()))
            : Missing::at(CoverageMapFile::in(Workspace::coverage()));

        return $removed instanceof CannotJudge ? $removed : $why;
    }

    /** Whether a child wrote the map, its end waited for. */
    private function childWrote(): bool
    {
        if ($this->child <= 0) {
            return false;
        }

        pcntl_waitpid($this->child, $status);

        return is_int($status) && pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGUSR2;
    }

    private function written(): Written|CannotJudge
    {
        return $this->project->write(
            CoverageMapFile::in(Workspace::coverage()),
            Contents::of(CoverageMapFile::encode(MapText::of($this->map), $this->at, $this->keys, $this->level)),
        );
    }
}
