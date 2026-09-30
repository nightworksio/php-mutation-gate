<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Order;

use function array_column;
use function array_flip;
use function array_key_exists;
use function array_map;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\NewestProofs;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Reach\Reach;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Unit\Unit;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\TimeoutTriage;

use function usort;

/**
 * The order a budgeted run takes units in (ADR-0008, decision 1): by risk,
 * the riskiest first; among the units of least risk, the most recently
 * changed first; and by path between units otherwise tied.
 */
final readonly class RiskOrder
{
    /** @param array<string, string> $changed when each unit last changed, as its instant is written, by its path */
    private function __construct(
        private Reach $reach,
        private NewestProofs $newest,
        private TimeoutTriage $triage,
        private array $changed,
    ) {
    }

    public static function of(Reach $reach, NewestProofs $newest, TimeoutTriage $triage): self
    {
        return new self($reach, $newest, $triage, []);
    }

    /**
     * This order, knowing when each of these units last changed.
     *
     * @param ByPath<Instant> $lastChanged
     */
    public function knowing(ByPath $lastChanged): self
    {
        $changed = [];

        foreach ($lastChanged as $path => $at) {
            $changed[$path->value()] = $at->value();
        }

        return new self($this->reach, $this->newest, $this->triage, $changed);
    }

    /** How much a unit risks. */
    public function riskOf(Unit $unit): Risk
    {
        $last = $this->newest->of($unit->path());

        return match (true) {
            $this->reach->changesLinesOf($unit) => Risk::ChangedLines,
            $last instanceof Proof && $this->unsettled($last) => Risk::Unsettled,
            ! $last instanceof Proof => Risk::NeverMutated,
            $this->reach->reaches($unit) => Risk::Reached,
            default => Risk::Rest,
        };
    }

    /** The paths of those of these units of least risk: the ones recency orders. */
    public function least(Units $units): Paths
    {
        $least = Paths::none();

        foreach ($units as $unit) {
            $least = $this->riskOf($unit) === Risk::Rest ? $least->with($unit->path()) : $least;
        }

        return $least;
    }

    /**
     * The units, the riskiest first; among the least risky, the most recently
     * changed first, and those no commit changed after them; then by path.
     */
    public function ordered(Units $units): Units
    {
        $ranks = array_flip(array_map(static fn(Risk $risk): string => $risk->name, Risk::cases()));
        $keyed = [];

        foreach ($units as $unit) {
            $risk = $this->riskOf($unit);
            $path = $unit->path()->value();
            $changed = $risk === Risk::Rest && array_key_exists($path, $this->changed) ? $this->changed[$path] : '';
            $keyed[] = ['rank' => $ranks[$risk->name], 'changed' => $changed, 'path' => $path, 'unit' => $unit];
        }

        usort($keyed, static function (array $one, array $other): int {
            $first = [$one['rank'], $other['changed'], $one['path']];

            return $first <=> [$other['rank'], $one['changed'], $other['path']];
        });

        return Units::of(...array_column($keyed, 'unit'));
    }

    /** Whether a result holds a survivor, or a mutant too slow to judge. */
    private function unsettled(Proof $proof): bool
    {
        foreach ($proof->reported() as $mutant) {
            $judged = $this->triage->judged($mutant);

            if ($judged === MutantJudgement::Survived || $judged === MutantJudgement::TooSlowToJudge) {
                return true;
            }
        }

        return false;
    }
}
