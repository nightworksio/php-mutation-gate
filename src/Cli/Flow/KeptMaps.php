<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function is_string;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\KeptMap;
use NightWorksIO\MutationGate\Core\Coverage\MapLimits;
use NightWorksIO\MutationGate\Core\Coverage\Remeasured;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Access;
use NightWorksIO\MutationGate\Core\Proof\Companion;
use NightWorksIO\MutationGate\Core\Proof\ReadsOnly;
use NightWorksIO\MutationGate\Core\Proof\Scope;
use NightWorksIO\MutationGate\Core\Proof\Writing;
use NightWorksIO\MutationGate\Core\Runner\CoverageRead;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * The coverage maps a run keeps (ADR-0023): the one the last round of
 * `watch` left in the workspace, which a quick round's streams write their
 * recorded lines into (ADR-0147); the one the default branch keeps, left in
 * the workspace as it is for a run planned without a fresh map (ADR-0135);
 * and the whole map the plan handed on, kept beside the default branch's
 * ledger where it was measured in a clean working tree.
 *
 * @internal
 */
final readonly class KeptMaps
{
    private const string EARLY = <<<'SAID'
        The run is full and splits its streams, and no proof could spare a unit, so it plans without a fresh map:
        its streams record what their tests execute (ADR-0135).
        SAID;

    private const string NOT_KEPT = 'coverage.incremental is false, so no coverage map is kept.';

    private const string NOTHING_TO_KEEP = 'The plan handed on no coverage map at %s, so none is kept.';

    private const string DIRTY_NOT_KEPT = 'The coverage map was measured in a dirty working tree, so it is not kept.';

    public function __construct(private Adapters $adapters, private Settings $settings)
    {
    }

    /** The map the last round of `watch` left; none; or why it cannot be read. */
    public function fromWorkspace(): KeptMap|Missing|CannotJudge
    {
        $bytes = $this->adapters->project->read(CoverageMapFile::in(Workspace::coverage()));

        return $bytes instanceof Contents
            ? CoverageMapFile::kept($bytes->text(), $this->limits(), Vouching::by($this->adapters->maps))
            : $bytes;
    }

    /**
     * The map the last round left, with each test a quick round's streams
     * recorded taking the lines it executed in place of its entries
     * (ADR-0147), written back where the next round reads it; the map as it
     * was where it cannot be read or written, which the next round measures
     * against as before.
     */
    public function recorded(CoverageMap $recorded): void
    {
        $kept = $this->fromWorkspace();

        if (! $kept instanceof KeptMap) {
            return;
        }

        $this->adapters->project->write(
            CoverageMapFile::in(Workspace::coverage()),
            Contents::of(CoverageMapFile::encode(
                Remeasured::over($kept->map(), $recorded->tests(), $recorded),
                $kept->measuredAt(),
                $kept->keys(),
                $this->level(),
            )),
        );
    }

    /**
     * The map kept beside the default branch's ledger as it is, left in the
     * workspace for a run planned without a fresh map, which reads it for
     * its tests' times alone (ADR-0135); none where none is kept.
     */
    public function keptAsIs(Inventory $inventory): CoverageMeasured|NotGiven
    {
        $access = Access::of(
            $inventory->standing->runOn()->scope(),
            $inventory->standing->defaultBranch(),
            Writing::from($this->settings->proofs()->write()->value),
        );
        $kept = StoredCoverage::in($this->adapters->proofs, $access, $this->limits(), $this->adapters->maps)->map();
        $bytes = $kept instanceof KeptMap ? CoverageMapFile::keeping($kept, $this->limits(), $this->level()) : $kept;
        $written = is_string($bytes)
            ? $this->adapters->project->write(CoverageMapFile::in(Workspace::coverage()), Contents::of($bytes))
            : NotGiven::value();

        return $written instanceof Written
            ? CoverageMeasured::of(CoverageRead::from(Workspace::coverage()), self::EARLY)
            : NotGiven::value();
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

    /** The whole map the plan handed on, kept in a scope where it is clean and within the limits; or why not. */
    private function keptIn(Scope $scope): Written|NotWritten|ReadsOnly
    {
        $read = $this->fromWorkspace();
        $bytes = $read instanceof KeptMap ? CoverageMapFile::keeping($read, $this->limits(), $this->level()) : $read;

        return match (true) {
            $bytes instanceof Missing
                => NotWritten::because(sprintf(self::NOTHING_TO_KEEP, Workspace::coverage()->value())),
            $bytes instanceof CannotJudge => NotWritten::because($bytes->why()),
            ! $read->isClean() => ReadsOnly::because(self::DIRTY_NOT_KEPT),
            default => $this->adapters->proofs->keep($scope, Companion::Coverage, Contents::of($bytes)),
        };
    }

    /** How large a kept map may be: `proofs.limits.map`. */
    private function limits(): MapLimits
    {
        return MapLimits::kept($this->settings->proofs()->retained());
    }

    /** The level a map the run keeps or writes is packed at: `coverage.compressionLevel`. */
    private function level(): int
    {
        return $this->settings->proofs()->coverageCompressionLevel();
    }
}
