<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use Closure;

use function dirname;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Composer\PackageFiles;
use NightWorksIO\MutationGate\Core\Config\Toggle;
use NightWorksIO\MutationGate\Core\Cost\Laps;
use NightWorksIO\MutationGate\Core\Cost\Step;
use NightWorksIO\MutationGate\Core\Cost\StepTime;
use NightWorksIO\MutationGate\Core\Cost\StepTimes;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\Handed;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Runner\MutationRequest;
use NightWorksIO\MutationGate\Core\Runner\Withheld;
use NightWorksIO\MutationGate\Core\Test\Group;
use NightWorksIO\MutationGate\Core\Test\Suites;
use NightWorksIO\MutationGate\Core\Test\TestListing;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;

use function sprintf;

/**
 * The map a patched shard's run opens on: its own map the plan handed over,
 * with the lines of each value its files declare covered by the tests that
 * read it (ADR-0037), and each line of a match's arms by the tests of the
 * arms after it too, written again for Pest, which opens on the canary
 * group with it; or why the shard cannot open on it.
 *
 * @internal
 */
final readonly class ShardMap
{
    private const string NOT_PATCHED
        = 'pest.patch is on, but pest-plugin-mutate in %s is not patched. doctor says how to patch it.';

    private const string EMPTY_CANARY = 'pest.patch is on, but the canary group %s holds no test. Add one.';

    /**
     * Where the map another job handed over is written again for this job's
     * Pest, beside the results: one for each set of files a run mutates, as
     * the values those files declare are covered by the tests that read them.
     */
    private const string SHARED_MAP = '%s/shared-%s.coverage.php';

    /** What separates the files a shared map is written for in what its name digests. */
    private const string BETWEEN = "\n";

    /**
     * @param Closure(Withheld, Suites): (TestListing|CannotJudge) $listing the suites' tests and groups, as the
     *                                                                runner lists them
     */
    public function __construct(
        private Project $project,
        private PackageFiles $packages,
        private Patching $patching,
        private Remembered $remembered,
        private Closure $listing,
    ) {
    }

    /**
     * The file the map a run of these files opens on is written to, beside its results.
     *
     * @return non-empty-string
     */
    public static function fileFor(string $results, Paths $files): string
    {
        $digest = Digest::sha256Of(PathList::of($files)->joined(self::BETWEEN));

        return sprintf(self::SHARED_MAP, dirname($results), $digest->value());
    }

    /** A run that opens on the canary group with the map another job handed over, where it has one. */
    public function opened(
        Command $command,
        string $results,
        CoverageMap|Unshared $shared,
        MutationRequest $request,
    ): Command {
        if (! $shared instanceof CoverageMap) {
            return $command;
        }

        $map = self::fileFor($results, $request->files());
        $this->remembered->writeOnce($map, fn() => SharedCoverage::write($shared, $this->project, $map));

        $canary = $this->patching->canary();

        return $command->with([
            GateVariable::SharedCoverage->value => $map,
            GateVariable::SuiteSeconds->value => sprintf('%F', SharedCoverage::seconds($shared)),
            ...($canary instanceof Group ? [GateVariable::Canary->value => $canary->name()] : []),
        ]);
    }

    /**
     * The shard's own map the plan handed over, where a patched shard opens
     * on the canary group, each step that readied it timed; why it cannot,
     * or none where the run opens on its own suite.
     */
    public function of(MutationRequest $request, Laps $laps): OpenedMap
    {
        $handed = $request->coverage();

        if (! $this->patching->isOn() || ! $handed instanceof Handed || ! $request->judgedBy() instanceof WholeSuite) {
            return OpenedMap::unopened(Unshared::Coverage);
        }

        $from = $laps->now();

        $directory = $handed->own();

        $refusal = $this->refusal($request);

        $reading = fn(): CoverageMap|CannotJudge => SharedCoverage::in(
            $this->project,
            $directory,
            $this->remembered->vouching(),
        );
        $own = $refusal instanceof CannotJudge ? $refusal : $this->remembered->map($directory, $reading);

        return $own instanceof CoverageMap
            ? $this->read($request, $own, $laps, $laps->lap(Step::Coverage, $from))
            : OpenedMap::unopened($own);
    }

    /**
     * The shard's own map, with the lines of each value the run's files
     * declare covered by the tests one run judges its mutants by (ADR-0037),
     * and each line of a match's arms by the tests of the arms after it too;
     * or why the map of every file, which finds them, cannot be read. Under
     * `pest.valueReaders: false`, the map as it was handed over. The own
     * map's read, the read of the map of every file and the scan are each
     * timed as a step of their own.
     */
    private function read(MutationRequest $request, CoverageMap $own, Laps $laps, StepTime $read): OpenedMap
    {
        if (! $this->patching->steps()->on(Toggle::PestValueReaders)) {
            return OpenedMap::readied($own, StepTimes::of($read));
        }

        $from = $laps->now();
        $whole = new WholeMap($this->project, $this->remembered);
        $reads = $whole->covering($request, new HandedOver($own, $this->project), $own);

        if ($reads instanceof CannotJudge) {
            return OpenedMap::unopened($reads);
        }

        $wholeRead = $laps->lap(Step::ValueReaderCoverage, $from);
        $from = $laps->now();
        $selector = $whole->selector($request, $reads, $own, $this->patching->steps()->on(Toggle::PestShardScan));
        $map = $selector->armCoverage($request->files(), $selector->readCoverage($request->files(), $own));

        return OpenedMap::readied($map, StepTimes::of($read, $wholeRead, $laps->lap(Step::ValueReaders, $from)));
    }

    /** Why a shard cannot open on the canary group among the suites its opening run runs, if it cannot. */
    private function refusal(MutationRequest $request): TestListing|CannotJudge
    {
        $vendor = $this->project->absolute($this->project->vendor());

        if (! $this->remembered->patched(fn(): bool => Patch::isAppliedIn($vendor, $this->packages))) {
            return CannotJudge::because(sprintf(self::NOT_PATCHED, $this->project->vendor()->value()));
        }

        $canary = $this->patching->canary();
        $opensOn = $this->patching->opensOn($request->judgedBy(), handedAMap: true);
        $listing = ($this->listing)($request->withheld(), $request->narrowing()->suitesFor($opensOn));

        return $listing instanceof CannotJudge || ! $canary instanceof Group || $listing->groups()->has($canary)
            ? $listing
            : CannotJudge::because(sprintf(self::EMPTY_CANARY, $canary->name()));
    }
}
