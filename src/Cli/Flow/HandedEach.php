<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_flip;
use function array_map;
use function array_values;

use Generator;

use function hash;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\HandoffLimits;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Plan\ShardId;

use function sprintf;

/**
 * The map each of several shards was handed, in their order, as the handoff
 * reads one: shards handed the same bytes share one map, decoded once and
 * held only until the last of them.
 *
 * @internal
 */
final readonly class HandedEach
{
    /** What tells apart the maps of shards whose map cannot be read, each its own. */
    private const string UNREAD = 'unread %d';

    public function __construct(private Handoff $handoff, private Directory $project, private HandoffLimits $limits)
    {
    }

    /**
     * The map each of these shards was handed, by its place among them.
     *
     * @return Generator<int, CoverageMap|CannotJudge>
     */
    public function read(ShardId ...$shards): Generator
    {
        $listed = array_values($shards);
        $handed = array_map($this->handedKey(...), $listed);
        $last = array_flip($handed);
        $decoded = [];

        foreach ($listed as $at => $shard) {
            $key = $handed[$at];
            $decoded[$key] ??= $this->handoff->read($shard);

            yield $at => $decoded[$key];

            if ($last[$key] === $at) {
                unset($decoded[$key]);
            }
        }
    }

    /** What tells the map a shard was handed from another's: its bytes' digest, or the shard where it has none. */
    private function handedKey(ShardId $shard): string
    {
        $contents = $this->project->readAtMost(
            CoverageMapFile::in(Workspace::shardCoverage($shard)),
            $this->limits->packed(),
        );

        return $contents instanceof Contents
            ? hash(Digest::QUICK, $contents->text())
            : sprintf(self::UNREAD, $shard->number());
    }
}
