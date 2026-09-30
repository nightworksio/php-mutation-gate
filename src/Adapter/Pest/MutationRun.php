<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use Closure;

use function count;
use function dirname;

use NightWorksIO\MutationGate\Adapter\Pest\Order\Plan;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\Recorder;
use NightWorksIO\MutationGate\Adapter\Pest\Unexecutable\Judging;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Filter;
use NightWorksIO\MutationGate\Core\Test\Groups;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

use function sprintf;

/**
 * One Pest mutation run, from a fresh results file to the gate's records: a
 * patched shard opens on the canary group with the map another job handed
 * over, which the run then reads its mutants' covering tests from, and every
 * other run reads its own opening map once, for its records and for judging
 * its mutants on lines that are not executable.
 */
final readonly class MutationRun
{
    private const string BY_GROUP_ALONE
        = 'Pest selects held tests by the holds: groups its plugin adds for #[Holds], not by the filter %s.';

    private const string COMMA = "Pest's --path and --ignore split on commas, so Pest cannot mutate %s less %s.";

    private const string NOT_PATCHED
        = 'pest.patch is on, but pest-plugin-mutate in %s is not patched. Run mutation-gate pest:patch.';

    private const string EMPTY_CANARY = 'pest.patch is on, but the canary group %s holds no test. Add one.';

    /** Where the map another job handed over is written again for this job's Pest, beside the results. */
    private const string SHARED_MAP = '%s/shared.coverage.php';

    /** @param Closure(Withheld): (Groups|CannotJudge) $groups the suite's groups, as the runner lists them */
    public function __construct(
        private Project $project,
        private Shell $shell,
        private Patching $patching,
        private Remembered $remembered,
        private Closure $groups,
    ) {
    }

    /** Every mutant of the requested files, where there are any to mutate: Pest's `--path` never names none. */
    public function of(MutationRequest $request): MutationResult|CannotJudge
    {
        if (count($request->files()) === 0) {
            return MutationResult::of(Mutants::none(), 0);
        }

        $results = $this->project->freshResults();
        $shared = $results instanceof CannotJudge ? $results : $this->shared($request);

        return match (true) {
            $results instanceof CannotJudge => $results,
            $shared instanceof CannotJudge => $shared,
            default => $this->ran($request, $results, $shared),
        };
    }

    private function ran(
        MutationRequest $request,
        string $results,
        CoverageMap|Unshared $shared,
    ): MutationResult|CannotJudge {
        $command = Plan::handedOver($this->project, $request, $this->commandFor($request, $results, $shared));

        if ($command instanceof CannotJudge) {
            return $command;
        }

        $ran = $this->shell->run($command);
        $coverage = $shared instanceof CoverageMap
            ? new HandedOver($shared, $this->project)
            : CoverageFile::at(Recorder::coverageBeside($results));
        $result = new Interpretation($this->project, $this->patching)->of($ran, $results, $coverage);

        return $result instanceof CannotJudge || $coverage instanceof CannotJudge
            ? $result
            : new Judging($this->project, $this->shell)->of($result, $request, $results, $coverage);
    }

    private function commandFor(
        MutationRequest $request,
        string $results,
        CoverageMap|Unshared $shared,
    ): Command|CannotJudge {
        $judgedBy = $request->judgedBy();
        $files = PathList::of($request->files());
        $leftOut = PathList::of($request->leftOut());

        return match (true) {
            $judgedBy instanceof Filter => CannotJudge::because(sprintf(self::BY_GROUP_ALONE, $judgedBy->pattern())),
            $files->holdsAComma() || $leftOut->holdsAComma() => CannotJudge::because(
                sprintf(self::COMMA, $files->joined(', '), $leftOut->joined(', ')),
            ),
            default => $this->opened(
                Invocation::installedIn($this->project->vendor())->mutation($request, $judgedBy, $results),
                $results,
                $shared,
            ),
        };
    }

    /** A run that opens on the canary group with the map another job handed over, where it has one. */
    private function opened(Command $command, string $results, CoverageMap|Unshared $shared): Command
    {
        if (! $shared instanceof CoverageMap) {
            return $command;
        }

        $map = sprintf(self::SHARED_MAP, dirname($results));
        $this->remembered->writeOnce($map, fn() => SharedCoverage::write($shared, $this->project, $map));

        return $command->with([
            Patch::COVERAGE => $map,
            Patch::SECONDS => sprintf('%F', SharedCoverage::seconds($shared)),
            Patch::CANARY => $this->patching->canary()->name(),
        ]);
    }

    /**
     * The map another job handed over, where a patched shard opens on the
     * canary group, why it cannot, or none where the run opens on its own suite.
     */
    private function shared(MutationRequest $request): CoverageMap|Unshared|CannotJudge
    {
        $directory = $request->coverage();

        if (! $this->patching->isOn() || ! $directory instanceof Path || ! $request->judgedBy() instanceof WholeSuite) {
            return Unshared::Coverage;
        }

        $refusal = $this->refusal($request->withheld());

        $reading = fn(): CoverageMap|CannotJudge => SharedCoverage::in($this->project, $directory);

        return $refusal instanceof CannotJudge ? $refusal : $this->remembered->map($directory, $reading);
    }

    /** Why a shard cannot open on the canary group, if it cannot. */
    private function refusal(Withheld $withheld): Groups|CannotJudge
    {
        $vendor = $this->project->absolute($this->project->vendor());

        if (! $this->remembered->patched(static fn(): bool => Patch::isAppliedIn($vendor))) {
            return CannotJudge::because(sprintf(self::NOT_PATCHED, $this->project->vendor()->value()));
        }

        $groups = ($this->groups)($withheld);

        return $groups instanceof CannotJudge || $groups->has($this->patching->canary())
            ? $groups
            : CannotJudge::because(sprintf(self::EMPTY_CANARY, $this->patching->canary()->name()));
    }
}
