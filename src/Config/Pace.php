<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/**
 * How a runner paces its work: `runner.recycleAfter`, how many runs a batch holds for each place, 16;
 * `runner.warmSlack`, how long a warm worker may run past the end and the longest run's limit, such as `1m`; and
 * `runner.poll`, how many seconds the gate waits between looks at a process it waits on, 0.01; and `runner.processes`,
 * how many mutants it runs at once, one per core; and `runner.workerWait`, how long the gate waits for a warm worker
 * to start or to end, `timeouts.startUp` where no layer sets it. `Runner::paced()` takes it for the runner it chooses,
 * and `Gate::with()` for a layer that chooses none.
 */
final readonly class Pace implements Setting
{
    private function __construct(
        private int|Absent $recycleAfter,
        private string|Absent $warmSlack,
        private float|Absent $poll,
        private int|Absent $processes,
        private string|Absent $workerWait,
    ) {
    }

    public static function of(
        int|Absent $recycleAfter = new Absent(),
        string|Absent $warmSlack = new Absent(),
        float|Absent $poll = new Absent(),
        int|Absent $processes = new Absent(),
        string|Absent $workerWait = new Absent(),
    ): self {
        return new self($recycleAfter, $warmSlack, $poll, $processes, $workerWait);
    }

    /** This runner's config, paced so. */
    public function in(Json $runner): Json
    {
        return $runner
            ->with(Member::of('recycleAfter', $this->recycleAfter))
            ->with(Member::of('warmSlack', $this->warmSlack))
            ->with(Member::of('poll', $this->poll))
            ->with(Member::of('processes', $this->processes))
            ->with(Member::of('workerWait', $this->workerWait));
    }

    public function written(): Json
    {
        return Json::object(Member::of('runner', $this->in(Json::object())));
    }
}
