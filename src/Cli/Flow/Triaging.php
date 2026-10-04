<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use Closure;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Config\TestOrder;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Matrix\MatrixKind;
use NightWorksIO\MutationGate\Core\Order\KillSearch;
use NightWorksIO\MutationGate\Core\Order\Ordering;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\MutationResult;
use NightWorksIO\MutationGate\Core\Triage\Repeated;
use NightWorksIO\MutationGate\Core\Unit\Unit;

use function sprintf;

/**
 * `triage`: one unit run again and again as its runner runs it (ADR-0008,
 * decision 3), to list each mutant whose status varied. Each run is the
 * runner's own, as a shard's invocation is before its retries: no timeout is
 * retried, no survivor confirmed and no survivor checked by an analyser, and
 * nothing is recorded. The unit is judged by its tests, under the mutators
 * and the memory cap of a run, and each mutant's tests run in the order
 * asked: with killers first, the history every ledger the run reads learned
 * of the unit's files.
 */
final readonly class Triaging
{
    private const string NO_UNIT = '%s is not a unit the gate mutates: give a file of a tree, or a held path.';

    private const string CANNOT = 'Run %d of %d cannot judge: %s';

    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /**
     * The unit at a path, run this many times with its tests in this order,
     * each run handed to `$ran` as it ends; or why it cannot be.
     *
     * @param int<2, max>                          $runs
     * @param Closure(int, MutationResult): void $ran
     */
    public function triaged(Path $path, int $runs, TestOrder $order, Closure $ran): Repeated|CannotJudge
    {
        $inventory = Inventory::of($this->adapters, $this->settings);
        $unit = $inventory instanceof Inventory ? $this->unitAt($path, $inventory) : $inventory;

        if (! $unit instanceof Unit) {
            return $unit;
        }

        $request = $this->requestFor($unit, $order, $inventory);
        $results = [];

        for ($run = 1; $run <= $runs; ++$run) {
            $result = $this->adapters->runner->mutate($request);

            if ($result instanceof CannotJudge) {
                return CannotJudge::because(sprintf(self::CANNOT, $run, $runs, $result->why()));
            }

            $ran($run, $result);
            $results[] = $result->mutants();
        }

        return Repeated::of(...$results);
    }

    private function unitAt(Path $path, Inventory $inventory): Unit|CannotJudge
    {
        foreach ($inventory->units as $unit) {
            if ($unit->path()->equals($path)) {
                return $unit;
            }
        }

        return CannotJudge::because(sprintf(self::NO_UNIT, $path->value()));
    }

    private function requestFor(Unit $unit, TestOrder $order, Inventory $inventory): MutationRequest
    {
        $history = Ledgers::read($this->adapters->proofs, $inventory->standing, Writing::Never)
            ->killers()
            ->onlyIn($this->filesOf($unit, $inventory));

        return RunRequest::of($this->adapters, $this->settings, Paths::of($unit->path()), $unit->judgedBy())
            ->across($this->adapters->processes())
            ->searching(KillSearch::of(Ordering::of($order, $history), MatrixKind::FirstKiller));
    }

    /** The unit's files: its own, or every file within its held path. */
    private function filesOf(Unit $unit, Inventory $inventory): Paths
    {
        $files = Paths::none();

        foreach ($inventory->files as $fingerprint) {
            $file = $fingerprint->path();
            $files = $file->equals($unit->path()) || $file->within($unit->path()) ? $files->with($file) : $files;
        }

        return $files;
    }
}
