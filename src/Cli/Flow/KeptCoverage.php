<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Remeasured;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\Access;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * The coverage map a run measures against the one kept before it
 * (ADR-0023): each test file whose entry key is unchanged keeps its
 * entries, each whose key moved, and each new one, is measured again, and a
 * deleted one's entries are dropped. `plan`, `run` and `coverage` measure
 * against the map the default branch's runs keep beside its ledger, `watch`
 * against the one its last round left, of which a quick round measures
 * again only the test files that changed (ADR-0146). Every test is measured where nothing
 * is kept, the kept map cannot be read or was measured in a dirty working
 * tree, a test it holds is in no test file, every entry's key moved, or
 * `coverage.incremental` is false.
 *
 * @internal
 */
final readonly class KeptCoverage
{
    private const string MEASURED = 'Coverage: measured %d of %d test files again; kept the rest from %s.';

    private const string QUICK = <<<'SAID'
        Coverage, quick: measured %d of %d test files again; kept the rest from %s,
        and covered %d changed source files by the tests that map and the static graph show running them.
        SAID;

    private const string NONE_KEPT = 'Coverage: there is no kept map, so every test was measured.';

    private const string EVERY = 'Coverage: %s, so every test was measured.';

    private const string UNREAD = 'Coverage: %s So every test was measured.';

    private const string OFF = 'coverage.incremental is false';

    private const string DIRTY = 'the kept map was measured in a dirty working tree';

    private const string UNPLACED = 'the kept map holds a test that no test file holds now';

    private const string READ_BY_EVERY = '`%s` changed, and every coverage entry reads it';

    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /** The whole suite's map, built afresh where a run reads it. */
    public static function built(): CoverageRun
    {
        return CoverageRun::of(WholeSuite::tests(), Workspace::coverage());
    }

    /**
     * What a run measures where it asks for the whole suite: the kept map,
     * brought up to date and written where the run reads it, or the whole
     * suite, with the line that says which.
     */
    public function measuring(
        CoverageEntries|CannotJudge|NotGiven $entries,
        KeptMap|Missing|CannotJudge $kept,
        KeptFrom $from,
        SuiteCoverage|NotGiven $suites,
        QuickCoverage|NotGiven $quick = new NotGiven(),
    ): CoverageMeasured {
        $usable = $this->usable($kept, $from);

        return match (true) {
            ! $usable instanceof KeptMap => $this->every($usable),
            $entries instanceof CannotJudge => $this->every(sprintf(self::UNREAD, $entries->why())),
            $entries instanceof NotGiven => $this->every(sprintf(self::EVERY, self::OFF)),
            default => $this->updated($entries, $usable, $from, $suites, $quick),
        };
    }

    /**
     * The map a measure gives, run or read, with only what the listed suites
     * may judge in it, where the inventory says which suite holds each test;
     * or why there is none.
     */
    public function mapOf(CoverageMeasured $measured, SuiteCoverage|NotGiven $suites): CoverageMap|CannotJudge
    {
        $request = $measured->request();
        $asked = match (true) {
            $request instanceof CoverageRead => $request,
            $suites instanceof SuiteCoverage => $suites->whole($request),
            default => $this->adapters->covering($request),
        };
        $map = $this->adapters->runner->coverage($asked);

        return $map instanceof CoverageMap && $suites instanceof SuiteCoverage ? $suites->admitted($map) : $map;
    }

    /**
     * The entries of the project's test files, keyed as it stands, which a
     * run measures against the kept map and keys its own map by, through the
     * names the plan read, or read now where it read none; none where
     * `coverage.incremental` is false; or why they cannot be told.
     */
    public function entries(
        Inventory|CannotJudge $inventory,
        SuiteNames|CannotJudge|NotGiven $names = new NotGiven(),
    ): CoverageEntries|CannotJudge|NotGiven {
        return match (true) {
            ! $this->settings->proofs()->incrementalCoverage() => NotGiven::value(),
            $inventory instanceof CannotJudge => $inventory,
            default => CoverageEntries::of(
                $this->adapters,
                $this->settings,
                $this->setup,
                $inventory,
                $names instanceof NotGiven ? new NameGraph($this->adapters)->named($inventory->suite) : $names,
            ),
        };
    }

    /**
     * What a run reads its map from: what it asked for, or, asking for the
     * whole suite, that measured against the default branch's kept map or,
     * for `watch`, against the one its last round left, as a quick round
     * brings it up to date (ADR-0146); the whole suite where the project
     * cannot be listed.
     */
    public function forRun(
        Inventory|CannotJudge $inventory,
        CoverageEntries|CannotJudge|NotGiven $entries,
        CoverageRun|CoverageRead $asked,
        bool $ownMap,
        SuiteCoverage|NotGiven $suites,
        QuickRound|NotGiven $quick = new NotGiven(),
    ): CoverageMeasured {
        if ($asked instanceof CoverageRead) {
            return CoverageMeasured::asked($asked);
        }

        if ($inventory instanceof CannotJudge) {
            return $this->every(sprintf(self::UNREAD, $inventory->why()));
        }

        $access = Access::of(
            $inventory->standing->runOn()->scope(),
            $inventory->standing->defaultBranch(),
            Writing::from($this->settings->proofs()->write()->value),
        );
        $kept = $ownMap
            ? StoredCoverage::of(new KeptMaps($this->adapters, $this->settings)->fromWorkspace(), KeptFrom::LastRound)
            : StoredCoverage::in(
                $this->adapters->proofs,
                $access,
                MapLimits::kept($this->settings->proofs()->retained()),
                $this->adapters->maps,
            );
        $quickly = $ownMap && $quick instanceof QuickRound && $entries instanceof CoverageEntries
            ? QuickCoverage::of($this->adapters, $this->settings, $inventory->suite, $entries, $quick)
            : NotGiven::value();

        return $this->measuring($entries, $kept->map(), $kept->from(), $suites, $quickly);
    }

    /**
     * The kept map, where a run may measure against it; or the line that says
     * why every test is measured. The last round's map was measured in the
     * working tree `watch` watches, so only a stored map must be clean.
     */
    private function usable(KeptMap|Missing|CannotJudge $kept, KeptFrom $from): KeptMap|string
    {
        return match (true) {
            ! $this->settings->proofs()->incrementalCoverage() => sprintf(self::EVERY, self::OFF),
            $kept instanceof Missing => self::NONE_KEPT,
            $kept instanceof CannotJudge => sprintf(self::UNREAD, $kept->why()),
            $from->isStored() && ! $kept->isClean() => sprintf(self::EVERY, self::DIRTY),
            default => $kept,
        };
    }

    /** The kept map with every moved entry measured again; or the whole suite, where that cannot be told. */
    private function updated(
        CoverageEntries $entries,
        KeptMap $kept,
        KeptFrom $from,
        SuiteCoverage|NotGiven $suites,
        QuickCoverage|NotGiven $quick,
    ): CoverageMeasured {
        $moving = Moving::of($entries, $kept, $quick);

        return match (true) {
            $moving instanceof CannotJudge => $this->every(sprintf(self::UNREAD, $moving->why())),
            ! $moving->placesEvery() => $this->every(sprintf(self::EVERY, self::UNPLACED)),
            $moving->movesEvery() => $this->every($this->everyMoved($entries, $kept, $moving, $from)),
            default => $this->merged($kept, $moving, $from, $suites, $entries, $quick),
        };
    }

    /**
     * The kept map with the moved entries measured again, and the changed
     * source files a quick round covers, written where a run reads it.
     */
    private function merged(
        KeptMap $kept,
        Moving $moving,
        KeptFrom $from,
        SuiteCoverage|NotGiven $suites,
        CoverageEntries $entries,
        QuickCoverage|NotGiven $quick,
    ): CoverageMeasured {
        $moved = $suites instanceof SuiteCoverage
            ? $suites->measured($moving->moved())
            : TestPaths::of($moving->moved());
        $measured = count($moved->files()) === 0
            ? CoverageMap::of()
            : $this->adapters->runner->coverage(
                $this->adapters->covering(CoverageRun::of($moved, Workspace::remeasuredCoverage())),
            );
        $map = $measured instanceof CoverageMap
            ? Remeasured::over($kept->map(), $moving->held(), $measured, $this->changedSince($kept))
            : $measured;
        $map = $map instanceof CoverageMap && $quick instanceof QuickCoverage
            ? $quick->over($map, $entries, $this->adapters)
            : $map;
        $written = $map instanceof CoverageMap
            ? $this->adapters->project->write(
                CoverageMapFile::in(Workspace::coverage()),
                Contents::of(CoverageMapFile::encode(
                    $map,
                    Measuring::now($this->adapters),
                    level: $this->settings->proofs()->coverageCompressionLevel(),
                )),
            )
            : $map;

        $merged = CoverageMeasured::of(
            CoverageRead::from(Workspace::coverage()),
            $quick instanceof QuickCoverage
                ? sprintf(self::QUICK, count($moved->files()), $moving->total(), $from->value, $quick->sources())
                : sprintf(self::MEASURED, count($moved->files()), $moving->total(), $from->value),
        );

        return match (true) {
            ! $written instanceof Written => $this->every(sprintf(self::UNREAD, $written->why())),
            $from === KeptFrom::OwnScope => $merged->fromOwnScope(),
            default => $merged,
        };
    }

    /**
     * Every path changed since the commit a map was measured at, by the name
     * it had at that commit, as the map names it; none where that cannot be
     * told.
     */
    private function changedSince(KeptMap $kept): Paths|NotGiven
    {
        $at = $kept->measuredAt();
        $changes = $at instanceof MeasuredAt ? $this->adapters->changes->changesFrom($at->commit()) : NotGiven::value();

        if (! $changes instanceof Changes) {
            return NotGiven::value();
        }

        $paths = Paths::none();

        foreach ($changes as $change) {
            $paths = $paths->with($change->previousPath());
        }

        return $paths;
    }

    /** The whole suite measured, with the line that says why. */
    private function every(string $why): CoverageMeasured
    {
        return CoverageMeasured::of(self::built(), $why);
    }

    /**
     * Why every entry moved: a changed file every entry reads, where the
     * change since the kept map's commit names one; or how many test files
     * that is.
     */
    private function everyMoved(CoverageEntries $entries, KeptMap $kept, Moving $moving, KeptFrom $from): string
    {
        $at = $kept->measuredAt();
        $changes = $at instanceof MeasuredAt ? $this->adapters->changes->changesFrom($at->commit()) : Changes::none();
        $read = $changes instanceof Changes ? $entries->firstReadByEvery($changes) : NotGiven::value();

        return $read instanceof NotGiven
            ? sprintf(self::MEASURED, $moving->total(), $moving->total(), $from->value)
            : sprintf(self::EVERY, sprintf(self::READ_BY_EVERY, $read->value()));
    }
}
