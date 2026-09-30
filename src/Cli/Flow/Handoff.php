<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\KillHistoryFile;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * What a plan hands each shard: its coverage, as the gate's own map of every
 * test and its duration and the lines of the files that shard mutates alone,
 * and beside it the kill history of those files' functions (ADR-0013,
 * decision 2). A runner's own map, which may be code, never leaves the job
 * that read it.
 */
final readonly class Handoff
{
    private const string UNHANDED = 'Shard %d was handed no coverage map at %s. Hand every job the plan\'s %s.';

    public function __construct(private Directory $project)
    {
    }

    /** Each shard's map, and the history of its files' functions, in the directory `run` reads them from. */
    public function write(Plan $plan, CoverageMap $map, KillHistory $history): Written|CannotJudge
    {
        $written = Written::to(Workspace::coverage()->value());

        foreach ($plan as $shard) {
            $files = $this->filesOf($shard, $map);
            $wrote = $this->project->write(
                $this->fileOf($shard->id()),
                Contents::of(CoverageMapFile::encode($map->onlyFor($files))),
            );
            $wrote = $wrote instanceof CannotJudge ? $wrote : $this->project->write(
                $this->killersOf($shard->id()),
                Contents::of(KillHistoryFile::encode($history->onlyIn($files))),
            );

            if ($wrote instanceof CannotJudge) {
                return $wrote;
            }
        }

        return $written;
    }

    /**
     * The kill history a shard was handed; none where it was handed none, as
     * though no test had killed anything yet.
     */
    public function history(ShardId $shard): KillHistory|CannotJudge
    {
        $contents = $this->project->read($this->killersOf($shard));

        return match (true) {
            $contents instanceof Contents => KillHistoryFile::decode($contents->text()),
            $contents instanceof Missing => KillHistory::none(),
            default => $contents,
        };
    }

    /** The map a shard was handed. */
    public function read(ShardId $shard): CoverageMap|CannotJudge
    {
        $file = $this->fileOf($shard);
        $contents = $this->project->read($file);

        return match (true) {
            $contents instanceof Contents => CoverageMapFile::decode($contents->text()),
            $contents instanceof CannotJudge => $contents,
            default => CannotJudge::because(
                sprintf(self::UNHANDED, $shard->number(), $file->value(), Workspace::coverage()->value()),
            ),
        };
    }

    private function fileOf(ShardId $shard): Path
    {
        return CoverageMapFile::in(Workspace::shardCoverage($shard));
    }

    private function killersOf(ShardId $shard): Path
    {
        return KillHistoryFile::in(Workspace::shardCoverage($shard));
    }

    /** The covered files a shard mutates: each unit's file, and every file within a held path. */
    private function filesOf(Shard $shard, CoverageMap $map): Paths
    {
        $files = Paths::none();

        foreach ($map->files() as $file) {
            foreach ($shard->units() as $unit) {
                $files = $file->within($unit->path()) ? $files->with($file) : $files;
            }
        }

        return $files;
    }
}
