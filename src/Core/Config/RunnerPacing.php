<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function implode;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\Polling;
use NightWorksIO\MutationGate\Core\Runner\ProcessCount;
use NightWorksIO\MutationGate\Core\Runner\RunnerPace;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * What one layer of a config says of how a runner paces its work: `runner.recycleAfter`, `runner.warmSlack`,
 * `runner.poll`, `runner.processes` and `runner.workerWait`, which `RunnerLayer` holds.
 */
final readonly class RunnerPacing
{
    private function __construct(
        private int|Absent $recycleAfter,
        private Seconds|Absent $warmSlack,
        private float|int|Absent $poll,
        private int|Absent $processes,
        private Seconds|Absent $workerWait,
    ) {
    }

    public static function of(
        int|Absent $recycleAfter = new Absent(),
        Seconds|Absent $warmSlack = new Absent(),
        float|int|Absent $poll = new Absent(),
        int|Absent $processes = new Absent(),
        Seconds|Absent $workerWait = new Absent(),
    ): self {
        return new self($recycleAfter, $warmSlack, $poll, $processes, $workerWait);
    }

    /** Every key at the value it takes where every layer leaves it out. */
    public static function standard(): self
    {
        $pace = RunnerPace::standard();

        return new self($pace->recycleAfter(), $pace->warmSlack(), Polling::INTERVAL, Absent::setting(), Absent::setting());
    }

    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->recycleAfter, $later->recycleAfter),
            Absent::laid($this->warmSlack, $later->warmSlack),
            Absent::laid($this->poll, $later->poll),
            Absent::laid($this->processes, $later->processes),
            Absent::laid($this->workerWait, $later->workerWait),
        );
    }

    /** Whether the layer says none of them. */
    public function isUnset(): bool
    {
        return $this->recycleAfter instanceof Absent
            && $this->warmSlack instanceof Absent
            && $this->poll instanceof Absent
            && $this->processes instanceof Absent
            && $this->workerWait instanceof Absent;
    }

    public function pace(Seconds $startUp): RunnerPace
    {
        $standard = RunnerPace::standard();

        return RunnerPace::of(
            $this->recycleAfter instanceof Absent ? $standard->recycleAfter() : $this->recycleAfter,
            $this->warmSlack instanceof Seconds ? $this->warmSlack : $standard->warmSlack(),
            $this->workerWait instanceof Seconds ? $this->workerWait : $startUp,
        );
    }

    /** `runner.poll`: how long the gate waits between looks at a process it waits on to end by a deadline. */
    public function poll(): Seconds
    {
        return Seconds::of($this->poll instanceof Absent ? Polling::INTERVAL : $this->poll);
    }

    /**
     * `runner.processes`: how many mutants the runner runs at once on a
     * machine with these cores, where it runs them side by side; one per core
     * where no layer sets it, or one sets fewer than one.
     */
    public function processes(ProcessCount $cores): ProcessCount
    {
        return $this->processes instanceof Absent || $this->processes < 1
            ? $cores
            : ProcessCount::of($this->processes);
    }

    /** This runner's config with each key the layer writes in it. */
    public function written(Json $runner): Json
    {
        return $runner
            ->with(Member::of('recycleAfter', $this->recycleAfter))
            ->with(Member::of('warmSlack', WholeSeconds::duration($this->warmSlack)))
            ->with(Member::of('poll', $this->poll))
            ->with(Member::of('processes', $this->processes))
            ->with(Member::of('workerWait', WholeSeconds::duration($this->workerWait)));
    }

    /** These calls of a layer that chooses no runner, then its pace, where it says any. */
    public function onGate(PhpCalls $calls): PhpCalls
    {
        return $this->isUnset() ? $calls : $calls->and(PhpCalls::inWith($this->php()));
    }

    /** The call on a runner that says its pace; none where it says none. */
    public function onRunner(): string
    {
        return $this->isUnset() ? '' : sprintf('->paced(%s)', $this->php());
    }

    /** The builder's `Pace` as PHP writes it. */
    private function php(): string
    {
        return sprintf(
            'Pace::of(%s)',
            implode(', ', [
                ...$this->recycleAfter instanceof Absent ? [] : [sprintf('recycleAfter: %d', $this->recycleAfter)],
                ...$this->warmSlack instanceof Seconds
                    ? [sprintf('warmSlack: %s', PhpCalls::literal($this->warmSlack->written()))]
                    : [],
                ...$this->poll instanceof Absent ? [] : [sprintf('poll: %s', PhpCalls::literal((float) $this->poll))],
                ...$this->processes instanceof Absent ? [] : [sprintf('processes: %d', $this->processes)],
                ...$this->workerWait instanceof Seconds
                    ? [sprintf('workerWait: %s', PhpCalls::literal($this->workerWait->written()))]
                    : [],
            ]),
        );
    }
}
