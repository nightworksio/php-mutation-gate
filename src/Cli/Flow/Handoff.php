<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Written;

use function sprintf;

/**
 * The coverage a plan hands each shard, as the gate's own map: every test
 * and its duration, and the lines of the files that shard mutates alone. A
 * runner's own map, which may be code, never leaves the job that read it.
 */
final readonly class Handoff
{
    private const string UNHANDED = 'Shard %d was handed no coverage map at %s. Hand every job the plan\'s %s.';

    public function __construct(private Directory $project)
    {
    }

    /** Each shard's map, in the directory `run` reads it from. */
    public function write(Plan $plan, CoverageMap $map): Written|CannotJudge
    {
        $written = Written::to(Workspace::coverage()->value());

        foreach ($plan as $shard) {
            $kept = $map->onlyFor($this->filesOf($shard, $map));
            $wrote = $this->project->write($this->fileOf($shard->id()), Contents::of(CoverageMapFile::encode($kept)));

            if ($wrote instanceof CannotJudge) {
                return $wrote;
            }
        }

        return $written;
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
