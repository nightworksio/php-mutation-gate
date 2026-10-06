<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Remeasured;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Access;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Runner\CoverageRun;
use NightWorksIO\MutationGate\Core\Test\TestPaths;
use NightWorksIO\MutationGate\Core\Test\WholeSuite;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\ProofStore;

use function sprintf;

/**
 * The coverage map a run measures against the one kept before it (ADR-0023,
 * decisions 1 to 3): each test file whose entry key is unchanged keeps its
 * entries, each whose key moved, and each new one, is measured again, and a
 * deleted one's entries are dropped. `plan`, `run` and `coverage` measure
 * against the map the default branch's runs keep beside its ledger, `watch`
 * against the one its last round left. Every test is measured where nothing
 * is kept, the kept map cannot be read or was measured in a dirty working
 * tree, a test it holds is in no test file, every entry's key moved, or
 * `coverage.incremental` is false.
 */
final readonly class KeptCoverage
{
    private const string MEASURED = 'Coverage: measured %d of %d test files again; kept the rest from %s.';

    private const string NONE_KEPT = 'Coverage: there is no kept map, so every test was measured.';

    private const string EVERY = 'Coverage: %s, so every test was measured.';

    private const string UNREAD = 'Coverage: %s So every test was measured.';

    private const string OFF = 'coverage.incremental is false';

    private const string DIRTY = 'the kept map was measured in a dirty working tree';

    private const string UNPLACED = 'the kept map holds a test that no test file holds now';

    private const string READ_BY_EVERY = '`%s` changed, and every coverage entry reads it';

    private const string NOT_KEPT = 'coverage.incremental is false, so no coverage map is kept.';

    private const string NOTHING_TO_KEEP = 'The plan handed on no coverage map at %s, so none is kept.';

    private const string DIRTY_NOT_KEPT = 'The coverage map was measured in a dirty working tree, so it is not kept.';

    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /** The whole suite's map, built afresh where a run reads it. */
    public static function built(): CoverageRun
    {
        return CoverageRun::of(WholeSuite::tests(), Workspace::coverage());
    }

    /**
     * The map kept beside a ledger the run reads, its own scope's first and
     * then the default branch's, where its own scope's cannot be read; none;
     * or why the default branch's cannot be read.
     */
    public static function fromStore(ProofStore $store, Access $access): StoredCoverage
    {
        $scopes = [...$access->reads()];
        $read = Missing::at(Path::of(Companion::Coverage->value));

        foreach ($scopes as $at => $scope) {
            $bytes = $store->companion($scope, Companion::Coverage);
            $read = $bytes instanceof Contents ? CoverageMapFile::kept($bytes->text(), MapLimits::standard()) : $bytes;
            $from = $at === count($scopes) - 1 ? KeptFrom::DefaultBranch : KeptFrom::OwnScope;

            if ($read instanceof KeptMap) {
                return StoredCoverage::of($read, $from);
            }
        }

        return StoredCoverage::of($read, KeptFrom::DefaultBranch);
    }

    /** The map the last round of `watch` left; none; or why it cannot be read. */
    public function fromWorkspace(): KeptMap|Missing|CannotJudge
    {
        $bytes = $this->adapters->project->read(CoverageMapFile::in(Workspace::coverage()));

        return $bytes instanceof Contents ? CoverageMapFile::kept($bytes->text(), MapLimits::standard()) : $bytes;
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
    ): CoverageMeasured {
        $usable = $this->usable($kept, $from);

        return match (true) {
            ! $usable instanceof KeptMap => $this->every($usable),
            $entries instanceof CannotJudge => $this->every(sprintf(self::UNREAD, $entries->why())),
            $entries instanceof NotGiven => $this->every(sprintf(self::EVERY, self::OFF)),
            default => $this->updated($entries, $usable, $from),
        };
    }

    /**
     * The entries of the project's test files, keyed as it stands, which a
     * run measures against the kept map and keys its own map by; none where
     * `coverage.incremental` is false; or why they cannot be told.
     */
    public function entries(Inventory|CannotJudge $inventory): CoverageEntries|CannotJudge|NotGiven
    {
        return match (true) {
            ! $this->settings->proofs()->incrementalCoverage() => NotGiven::value(),
            $inventory instanceof CannotJudge => $inventory,
            default => CoverageEntries::of($this->adapters, $this->settings, $this->setup, $inventory),
        };
    }

    /**
     * What a run reads its map from: what it asked for, or, asking for the
     * whole suite, that measured against the default branch's kept map or,
     * for `watch`, against the one its last round left; the whole suite where
     * the project cannot be listed.
     */
    public function forRun(
        Inventory|CannotJudge $inventory,
        CoverageEntries|CannotJudge|NotGiven $entries,
        CoverageRun|CoverageRead $asked,
        bool $ownMap,
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
            ? StoredCoverage::of($this->fromWorkspace(), KeptFrom::LastRound)
            : self::fromStore($this->adapters->proofs, $access);

        return $this->measuring($entries, $kept->map(), $kept->from());
    }

    /**
     * Each test file's entry key over a map, which the map carries for the
     * verdict to keep; none where there are no entries or the keys cannot be
     * told, and the next run measures every test.
     */
    public static function keysOf(CoverageEntries|CannotJudge|NotGiven $entries, CoverageMap $map): EntryKeys|NotGiven
    {
        $keys = $entries instanceof CoverageEntries ? $entries->keysOf($map) : NotGiven::value();

        return $keys instanceof CannotJudge ? NotGiven::value() : $keys;
    }

    /**
     * Keep the whole map the plan handed on beside the default branch's
     * ledger, for a run on that branch, saying where; or why it is not kept.
     */
    public function keep(Access $access): Written|NotWritten|ReadsOnly
    {
        $scope = $access->writes();

        return match (true) {
            ! $this->settings->proofs()->incrementalCoverage() => ReadsOnly::because(self::NOT_KEPT),
            ! $scope instanceof Scope => $scope,
            default => $this->keptIn($scope),
        };
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
            $from->isStored() && ! $this->isClean($kept) => sprintf(self::EVERY, self::DIRTY),
            default => $kept,
        };
    }

    /** The whole map the plan handed on, kept in a scope where it is clean and within the limits; or why not. */
    private function keptIn(Scope $scope): Written|NotWritten|ReadsOnly
    {
        $read = $this->fromWorkspace();
        $bytes = $read instanceof KeptMap ? CoverageMapFile::keeping($read, MapLimits::standard()) : $read;

        return match (true) {
            $bytes instanceof Missing
                => NotWritten::because(sprintf(self::NOTHING_TO_KEEP, Workspace::coverage()->value())),
            $bytes instanceof CannotJudge => NotWritten::because($bytes->why()),
            ! $this->isClean($read) => ReadsOnly::because(self::DIRTY_NOT_KEPT),
            default => $this->adapters->proofs->keep($scope, Companion::Coverage, Contents::of($bytes)),
        };
    }

    /** Whether a map was measured at a commit, in a clean working tree. */
    private function isClean(KeptMap $kept): bool
    {
        $at = $kept->measuredAt();

        return $at instanceof MeasuredAt && ! $at->isDirty();
    }

    /** The kept map with every moved entry measured again; or the whole suite, where that cannot be told. */
    private function updated(CoverageEntries $entries, KeptMap $kept, KeptFrom $from): CoverageMeasured
    {
        $moving = Moving::of($entries, $kept);

        return match (true) {
            $moving instanceof CannotJudge => $this->every(sprintf(self::UNREAD, $moving->why())),
            ! $moving->placesEvery() => $this->every(sprintf(self::EVERY, self::UNPLACED)),
            $moving->movesEvery() => $this->every($this->everyMoved($entries, $kept, $moving, $from)),
            default => $this->merged($kept, $moving, $from),
        };
    }

    /** The kept map with the moved entries measured again, written where a run reads it. */
    private function merged(KeptMap $kept, Moving $moving, KeptFrom $from): CoverageMeasured
    {
        $moved = $moving->moved();
        $measured = count($moved) === 0
            ? CoverageMap::empty()
            : $this->adapters->runner->coverage(
                $this->adapters->covering(CoverageRun::of(TestPaths::of($moved), Workspace::remeasuredCoverage())),
            );
        $written = $measured instanceof CoverageMap
            ? $this->adapters->project->write(
                CoverageMapFile::in(Workspace::coverage()),
                Contents::of(CoverageMapFile::encode(
                    Remeasured::over($kept->map(), $moving->held(), $measured),
                    Measuring::now($this->adapters),
                )),
            )
            : $measured;

        $merged = CoverageMeasured::of(
            CoverageRead::from(Workspace::coverage()),
            sprintf(self::MEASURED, count($moved), $moving->total(), $from->value),
        );

        return match (true) {
            ! $written instanceof Written => $this->every(sprintf(self::UNREAD, $written->why())),
            $from === KeptFrom::OwnScope => $merged->fromOwnScope(),
            default => $merged,
        };
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
