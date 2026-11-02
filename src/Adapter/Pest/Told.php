<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\Verdicts;
use NightWorksIO\MutationGate\Adapter\Pest\Warm\WarmDirectory;
use NightWorksIO\MutationGate\Core\Pruning\Pruned;
use NightWorksIO\MutationGate\Core\Pruning\PrunedList;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Workers;

/**
 * What a mutation run tells the patched plugin through Pest's environment,
 * each list written beside the run's results: the only mutants a run again
 * makes (see OnlyList), the mutators it prunes (see PrunedFile), whether each
 * mutant's own run is narrowed (see CoveringFiles), where it waits for the
 * gate's verdicts (see Verdicts), where its warm workers share their files
 * (see Warm\Pool), and the bounds of each mutant's limits.
 *
 * @internal
 */
final readonly class Told
{
    /** @param list<string> $only the native ids of the only mutants the run makes, or none for every one */
    public function __construct(
        private Project $project,
        private Patching $patching,
        private array $only,
        private bool $narrows,
    ) {
    }

    /**
     * The variables a run of this request tells Pest, its lists written
     * beside these results.
     *
     * @return array<string, string>
     */
    public function of(MutationRequest $request, string $results, bool $handsOff, LimitBounds $bounds): array
    {
        $pruned = $request->narrowing()->pruned();

        return [
            ...$this->only === []
                ? []
                : [GateVariable::Only->value => OnlyList::write(OnlyList::beside($results), ...$this->only)],
            ...$this->pruning($pruned, $results),
            ...$this->narrows ? [GateVariable::Narrow->value => '1'] : [],
            ...$handsOff ? [GateVariable::Verdicts->value => Verdicts::beside($results)] : [],
            ...$this->forks($request) ? [GateVariable::Warm->value => WarmDirectory::beside($results)] : [],
            ...$this->patching->bounding($bounds),
        ];
    }

    /** Whether each mutant's own run forks from its place's warm worker: under the patch, where the request forks. */
    private function forks(MutationRequest $request): bool
    {
        return $this->patching->isOn() && $request->pool()->workers() === Workers::Fork;
    }

    /**
     * The list of the mutators the run leaves out of its unchanged files,
     * beside its results; none where it leaves none out.
     *
     * @return array<string, string>
     */
    private function pruning(Pruned $pruned, string $results): array
    {
        return $pruned->isNone() ? [] : [
            GateVariable::Pruned->value => PrunedFile::write(
                PrunedList::beside($results),
                $pruned,
                $this->project->root(),
            ),
        ];
    }
}
