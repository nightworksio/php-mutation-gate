<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use function array_keys;

use Closure;

use const INF;

use function ksort;

use NightWorksIO\MutationGate\Core\Runner\ProcessEnds;
use NightWorksIO\MutationGate\Core\Runner\Ran;
use NightWorksIO\MutationGate\Core\Runner\StartedProcesses;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function usort;

/** Commands that had all ended as they were handed back, keeping whether they were stopped, and whether awaited. */
final class StartedFake implements StartedProcesses
{
    private bool $stopped = false;

    private bool $awaited = false;

    public function __construct(private readonly ProcessEnds $ends)
    {
    }

    public function ends(): ProcessEnds
    {
        $this->awaited = true;

        return $this->ends;
    }

    /**
     * The ends handed back, given to the settling one at a time, the shortest
     * first, one with no time after every one with a time, each let go before
     * its turn ending as stopped.
     */
    public function endsSettling(Closure $settled): ProcessEnds
    {
        $all = [...$this->ends()];
        $order = array_keys($all);
        $took = static fn(int $at): float => $all[$at]->duration() instanceof Seconds ? $all[$at]->duration()->seconds() : INF;
        usort($order, static fn(int $one, int $other): int => $took($one) <=> $took($other));
        $fed = [];

        foreach ($order as $at) {
            $fed += [$at => $all[$at]];

            foreach ($settled($fed) as $letGo) {
                $fed += [$letGo => Ran::stopped('')];
            }
        }

        ksort($fed);

        return ProcessEnds::of(...$fed);
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    /** @return array{bool, bool} whether they were awaited, and whether stopped */
    public function handled(): array
    {
        return [$this->awaited, $this->stopped];
    }
}
