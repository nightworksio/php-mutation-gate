<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function is_dir;
use function mkdir;

use NightWorksIO\MutationGate\Adapter\Pest\Split\RecordedMap;
use NightWorksIO\MutationGate\Adapter\Pest\Split\RecordedSuite;
use NightWorksIO\MutationGate\Core\Config\Settings;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\StreamedSuite;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\FileMode;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Root;
use NightWorksIO\MutationGate\Core\Local\LocalLoop;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

/**
 * What a shard's streams record of each test's lines: in a run planned
 * without a fresh map, the map and the suite they recorded, left beside the
 * shard's result for the verdict (ADR-0135); in a quick round that records
 * lines, the tests their parents ran whole, taken into the kept map
 * (ADR-0147).
 *
 * @internal
 */
final readonly class ShardRecording
{
    public function __construct(private Adapters $adapters, private Settings $settings, private Setup $setup)
    {
    }

    /** Whether the run is a quick round whose streams record their tests' lines into the kept map (ADR-0147). */
    public function recordsLines(): bool
    {
        return $this->adapters->loop instanceof LocalLoop && $this->adapters->loop->recordsLines();
    }

    /** The directory a shard's streams record each test's lines in, made ready and holding nothing recorded before. */
    public function directory(Shard $shard): string
    {
        $directory = Root::here()->at(Workspace::recording($shard->id()))->value();

        if (! is_dir($directory)) {
            mkdir($directory, FileMode::OWNER_ONLY, recursive: true);
        }

        RecordedMap::cleared($directory);

        return $directory;
    }

    /** What a shard's streams recorded, left where the run reads it; nothing in a run that records no lines. */
    public function left(Plan $plan, ShardId $id, Path $results): void
    {
        if ($plan->briefing()->isEarly()) {
            $this->standInLeft($id, $results);

            return;
        }

        if ($this->recordsLines()) {
            $this->kept($id);
        }
    }

    /** The tests a quick round's streams' parents ran whole, taken into the kept map with the lines they recorded. */
    private function kept(ShardId $id): void
    {
        $recorded = RecordedMap::in(
            Root::here()->at(Workspace::recording($id))->value(),
            Root::here(),
            wholeOnly: true,
        );

        if ($recorded instanceof CoverageMap) {
            new KeptCoverage($this->adapters, $this->settings, $this->setup)->recorded($recorded);
        }
    }

    /** The map and the suite a shard's streams recorded, left beside its result for the verdict. */
    private function standInLeft(ShardId $id, Path $results): void
    {
        $directory = Root::here()->at(Workspace::recording($id))->value();
        $map = RecordedMap::in($directory, Root::here());
        $suite = RecordedSuite::in($directory, Root::here());

        if ($map instanceof CoverageMap) {
            $this->adapters->project->write(
                Workspace::recorded($results, $id),
                Contents::of(CoverageMapFile::encode($map, Unplaced::map())),
            );
        }

        if ($suite instanceof StreamedSuite) {
            $this->adapters->project->write(Workspace::streamedSuite($results, $id), Contents::of($suite->encoded()));
        }
    }
}
