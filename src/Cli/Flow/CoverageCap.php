<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Runner\Exhaustion;
use NightWorksIO\MutationGate\Core\Runner\MemoryCap;
use NightWorksIO\MutationGate\Core\Runner\PhpUnitConfig;

use function sprintf;

/**
 * The memory cap a plan holds the suite's coverage run to: `runner.memory`,
 * or the project's own `memory_limit` where that lifts it (ADR-0004,
 * decision 9).
 */
final readonly class CoverageCap
{
    private const string OVER_CAP = <<<'SAID'
        The largest process of the suite's coverage run held %s resident, more than the %s each mutant's
        process may hold, so its mutants cannot be judged under that cap. Resident memory counts more than
        memory_limit does. %s
        SAID;

    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /**
     * The map of the coverage run just run, where the suite, unmutated, held
     * no more than the cap in force in it: `runner.memory`, or the project's
     * own `memory_limit` where that lifts it; or why its mutants cannot be
     * judged under that cap (ADR-0004, decision 9). The peak is the most
     * resident memory of a process, an upper bound on what `memory_limit`
     * counts. A peak the system does not count, or a map another job wrote,
     * refuses nothing.
     */
    public function holding(CoverageMap $map, MemoryCap|NotGiven $peak): CoverageMap|CannotJudge
    {
        $cap = ProjectMemoryLimit::inForce(
            $this->adapters->project,
            PhpUnitConfig::among($this->adapters->runner->definitions()),
            $this->settings->runner()->memory(),
        );

        return $peak instanceof MemoryCap && $cap->isExceededBy($peak)
            ? CannotJudge::because(sprintf(self::OVER_CAP, $peak->written(), $cap->written(), Exhaustion::ADVICE))
            : $map;
    }
}
