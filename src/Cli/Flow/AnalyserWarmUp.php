<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\Analysis\AnalyserIdentity;
use NightWorksIO\MutationGate\Core\Analysis\DependentCap;
use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Port\StaticChecker;
use Psr\Clock\ClockInterface;

/**
 * The static analyser's one run over a shard's original files (ADR-0020),
 * timed, made the first time a check of its survivors asks for it and kept
 * for every check after: the checks of each chunk's survivors, where the run
 * stops once it cannot pass, and the check of the rest once the run is done.
 * Where the run fails, no survivor can be checked. Where the plan warmed the
 * analyser up and its cache came with the plan, the run starts from that
 * cache, and takes the plan's longer time for a check's (ADR-0164).
 *
 * @internal
 */
final class AnalyserWarmUp
{
    /** @var list<WarmedUp|Unchecked> the run, once made */
    private array $made = [];

    public function __construct(private readonly int $dependents = DependentCap::STANDARD)
    {
    }

    public function of(
        StaticChecker $checker,
        AnalyserIdentity $identity,
        Adapters $adapters,
        ClockInterface $clock,
    ): WarmedUp|Unchecked {
        if ($this->made === []) {
            $started = $clock->now();
            $found = $checker->findings(Paths::none(), $adapters->withheld);
            $own = Seconds::between($started, $clock->now());
            $planned = PlannedWarmUp::secondsFor($adapters->project, $identity);
            $took = $planned instanceof Seconds && $planned->seconds() > $own->seconds() ? $planned : $own;
            $this->made = [$found instanceof CannotJudge
                ? Unchecked::NoWarmUp
                : new WarmedUp(
                    $checker,
                    $identity,
                    $found,
                    $took,
                    new Dependents(new NameGraph($adapters), DependentCap::atMost($this->dependents)),
                )];
        }

        return $this->made[0];
    }
}
