<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_any;
use function array_flip;
use function array_map;

use Generator;

use function getenv;
use function hash;
use function implode;
use function ini_get;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMap;
use NightWorksIO\MutationGate\Core\Coverage\CoverageMapFile;
use NightWorksIO\MutationGate\Core\Coverage\EntryKeys;
use NightWorksIO\MutationGate\Core\Coverage\HandoffLimits;
use NightWorksIO\MutationGate\Core\Coverage\MapSlice;
use NightWorksIO\MutationGate\Core\Coverage\MapSlices;
use NightWorksIO\MutationGate\Core\Coverage\MapText;
use NightWorksIO\MutationGate\Core\Coverage\MeasuredAt;
use NightWorksIO\MutationGate\Core\Coverage\Unplaced;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\Gzip;
use NightWorksIO\MutationGate\Core\Format\TooLarge;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Order\KillHistory;
use NightWorksIO\MutationGate\Core\Plan\Plan;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardId;
use NightWorksIO\MutationGate\Core\Proof\KillHistoryFile;
use NightWorksIO\MutationGate\Core\Proof\SubsumptionFile;
use NightWorksIO\MutationGate\Core\Runner\ChildVariable;
use NightWorksIO\MutationGate\Core\Subsumption\Subsumption;
use NightWorksIO\MutationGate\Core\Unit\Units;
use NightWorksIO\MutationGate\Core\Written;
use NightWorksIO\MutationGate\Port\MapEngine;

use function sprintf;

/**
 * What a plan hands each shard: its coverage, as the gate's own map of every
 * test and its duration and the lines of the files that shard mutates alone,
 * and beside it the kill history of those files' functions (ADR-0013); every
 * shard the plan's whole map, which its runner reads only to find the tests
 * that run a line reading a value a mutant changes (ADR-0006); and the verdict
 * the lines of every unit the plan considered, for the kill matrix (ADR-0014).
 * A runner's own map, which may be code, never leaves the job that read it.
 * Where an engine takes the map's bulk off PHP (ADR-0118), it writes the
 * shards' and the verdict's maps from the whole one, and vouches for the
 * maps read back; where it declines, the gate writes and reads them itself.
 *
 * @internal
 */
final readonly class Handoff
{
    private const string UNHANDED = 'Shard %d was handed no coverage map at %s. Hand every job the plan\'s %s.';

    private const string NO_ENGINE = 'No engine writes the maps.';

    /** What tells apart the maps of shards whose map cannot be read, each its own. */
    private const string UNREAD = 'unread %d';

    private const string NO_MATRIX = 'The verdict was handed no coverage map at %s. Hand it the plan\'s %s.';

    public function __construct(
        private Directory $project,
        private HandoffLimits $limits,
        private MapEngine|NotGiven $engine = new NotGiven(),
        private int $level = Gzip::LEVEL,
    ) {
    }

    /** The limits this process reads a map handed over within, by the memory it has. */
    public static function limits(): HandoffLimits
    {
        return HandoffLimits::under(
            ini_get('memory_limit'),
            getenv(ChildVariable::HandoffPacked->value),
            getenv(ChildVariable::HandoffUnpacked->value),
        );
    }

    /**
     * The directory the plan leaves the map every shard opens on in, where
     * the runner readies it once as the plan is made (ADR-0163).
     */
    public static function opened(): Path
    {
        return Path::of(sprintf('%s/opened', Workspace::coverage()->value()));
    }

    /**
     * The whole map, with each test file's entry key where the run keeps
     * one, each shard's map and the history of its files' functions, in the
     * directory `run` reads them from; the verdict's map; and the map every
     * shard opens on, where the runner readied one, none of an earlier plan's
     * left behind otherwise; nothing where the runner could not ready it. The
     * whole map is the one a child began writing beside the readying, where
     * one did (ADR-0169).
     */
    public function write(
        Plan $plan,
        CoverageMap $map,
        KillHistory $history,
        MeasuredAt|Unplaced $at,
        EntryKeys|NotGiven $keys = new NotGiven(),
        Subsumption|NotGiven $held = new NotGiven(),
        CoverageMap|NotGiven|CannotJudge $opened = new NotGiven(),
        HandedWhole|NotGiven $whole = new NotGiven(),
    ): Written|CannotJudge {
        $writing = HandedWhole::of($whole, $this->project, $map, $at, $keys, $this->level);

        return $opened instanceof CannotJudge
            ? $writing->abandonedFor($opened)
            : $this->writing($plan, $map, $history, $held, $opened, $writing);
    }

    /** The map the verdict was handed: the lines of every unit the plan considered. */
    public function forVerdict(): CoverageMap|CannotJudge
    {
        $file = CoverageMapFile::in(Workspace::verdictCoverage());
        $contents = $this->project->readAtMost($file, $this->limits->packed());

        return match (true) {
            $contents instanceof Contents => $this->decoded($contents),
            $contents instanceof TooLarge => CannotJudge::because($contents->why()),
            $contents instanceof CannotJudge => $contents,
            default => CannotJudge::because(
                sprintf(self::NO_MATRIX, $file->value(), Workspace::coverage()->value()),
            ),
        };
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

    /**
     * Which mutant subsumes which in a shard's files, as it was handed for
     * its run to hold the subsumed back; nothing where its run holds none
     * back.
     */
    public function subsumption(ShardId $shard): Subsumption|NotGiven|CannotJudge
    {
        $contents = $this->project->read($this->subsumptionOf($shard));

        return match (true) {
            $contents instanceof Contents => SubsumptionFile::decode($contents->text()),
            $contents instanceof Missing => NotGiven::value(),
            default => $contents,
        };
    }

    /** The map a shard was handed. */
    public function read(ShardId $shard): CoverageMap|CannotJudge
    {
        $file = $this->fileOf($shard);
        $contents = $this->project->readAtMost($file, $this->limits->packed());

        return match (true) {
            $contents instanceof Contents => $this->decoded($contents),
            $contents instanceof TooLarge => CannotJudge::because($contents->why()),
            $contents instanceof CannotJudge => $contents,
            default => CannotJudge::because(
                sprintf(self::UNHANDED, $shard->number(), $file->value(), Workspace::coverage()->value()),
            ),
        };
    }

    /**
     * The map each of these shards was handed, by its place among them, in
     * their order: shards handed the same bytes share one map, decoded once
     * and held only until the last of them.
     *
     * @return Generator<int, CoverageMap|CannotJudge>
     */
    public function readEach(ShardId ...$shards): Generator
    {
        $handed = array_map($this->handedKey(...), $shards);
        $last = array_flip($handed);
        $decoded = [];

        foreach ($shards as $at => $shard) {
            $key = $handed[$at];
            $decoded[$key] ??= $this->read($shard);

            yield $at => $decoded[$key];

            if ($last[$key] === $at) {
                unset($decoded[$key]);
            }
        }
    }

    /** What tells the map a shard was handed from another's: its bytes' digest, or the shard where it has none. */
    private function handedKey(ShardId $shard): string
    {
        $contents = $this->project->readAtMost($this->fileOf($shard), $this->limits->packed());

        return $contents instanceof Contents
            ? hash(Digest::QUICK, $contents->text())
            : sprintf(self::UNREAD, $shard->number());
    }

    /** What `write()` writes, once the runner readied the map every shard opens on, or none. */
    private function writing(
        Plan $plan,
        CoverageMap $map,
        KillHistory $history,
        Subsumption|NotGiven $held,
        CoverageMap|NotGiven $opened,
        HandedWhole $whole,
    ): Written|CannotJudge {
        $written = Written::to(Workspace::coverage()->value());
        $considered = Units::none();
        $text = MapText::of($map);
        $wrote = $whole->left();

        if ($wrote instanceof CannotJudge) {
            return $wrote;
        }

        $slices = MapSlices::of();
        $beside = [];

        foreach ($plan as $shard) {
            $considered = Units::of(...$considered, ...$shard->units());
            $files = $this->filesOf($shard->units(), $map);
            $slices = $slices->with(MapSlice::of($this->fileOf($shard->id()), $files));
            $beside[self::keyOf($files)] ??= self::besideFiles($files, $history, $held);
            $wrote = $this->besideShard($shard, ...$beside[self::keyOf($files)]);

            if ($wrote instanceof CannotJudge) {
                return $wrote;
            }
        }

        $considered = Units::of(...$considered, ...$plan->considered()->proved(), ...$plan->considered()->carried());
        $slices = $slices->with(
            MapSlice::of(CoverageMapFile::in(Workspace::verdictCoverage()), $this->filesOf($considered, $map)),
        );
        $wrote = $this->sliced($text, $slices);
        $left = $wrote instanceof CannotJudge ? $wrote : $this->opening($opened);

        return $left instanceof CannotJudge ? $left : $written;
    }

    /** A map handed over, read within the limits, built straight from its text where the engine vouches for it. */
    private function decoded(Contents $contents): CoverageMap|CannotJudge
    {
        return CoverageMapFile::decode($contents->text(), $this->limits, Vouching::by($this->engine));
    }

    /**
     * The shards' and the verdict's maps, each the whole map's for its files:
     * written by the engine where it writes them, and by the gate otherwise.
     */
    private function sliced(MapText $text, MapSlices $slices): Written|NotWritten|CannotJudge
    {
        $split = $this->engine instanceof MapEngine
            ? $this->engine->split(CoverageMapFile::in(Workspace::coverage()), $slices)
            : NotWritten::because(self::NO_ENGINE);

        $encoded = [];

        foreach ($split instanceof Written ? [] : $slices as $slice) {
            $encoded[self::keyOf($slice->files())] ??= Contents::of(
                CoverageMapFile::encode($text->onlyFor($slice->files()), Unplaced::map(), level: $this->level),
            );
            $wrote = $this->project->write($slice->into(), $encoded[self::keyOf($slice->files())]);

            if ($wrote instanceof CannotJudge) {
                return $wrote;
            }
        }

        return $split;
    }

    /**
     * What a shard of these files is handed beside its map: the history of
     * their functions, and which mutant subsumes which in them where its run
     * holds the subsumed back; written once for every shard of the same files.
     *
     * @return array{Contents, Contents|NotGiven}
     */
    private static function besideFiles(Paths $files, KillHistory $history, Subsumption|NotGiven $held): array
    {
        return [
            Contents::of(KillHistoryFile::encode($history->onlyIn($files))),
            $held instanceof Subsumption ? Contents::of(SubsumptionFile::encode($held->onlyIn($files))) : $held,
        ];
    }

    /** What a shard is handed beside its map (see besideFiles), written for it. */
    private function besideShard(Shard $shard, Contents $killers, Contents|NotGiven $subsumed): Written|CannotJudge
    {
        $wrote = $this->project->write($this->killersOf($shard->id()), $killers);

        return $wrote instanceof CannotJudge || ! $subsumed instanceof Contents
            ? $wrote
            : $this->project->write($this->subsumptionOf($shard->id()), $subsumed);
    }

    /** What the same files are known by, wherever they are listed again. */
    private static function keyOf(Paths $files): string
    {
        $listed = [];

        foreach ($files as $file) {
            $listed[] = $file->value();
        }

        return hash('xxh128', implode("\n", $listed));
    }

    /** The map every shard opens on, where the runner readied one; none left behind of an earlier plan otherwise. */
    private function opening(CoverageMap|NotGiven $opened): Written|Missing|CannotJudge
    {
        $file = CoverageMapFile::in(self::opened());

        return $opened instanceof CoverageMap
            ? $this->project->write(
                $file,
                Contents::of(CoverageMapFile::encode(MapText::of($opened), Unplaced::map(), level: $this->level)),
            )
            : $this->project->remove($file);
    }

    private function fileOf(ShardId $shard): Path
    {
        return CoverageMapFile::in(Workspace::shardCoverage($shard));
    }

    private function killersOf(ShardId $shard): Path
    {
        return KillHistoryFile::in(Workspace::shardCoverage($shard));
    }

    private function subsumptionOf(ShardId $shard): Path
    {
        return SubsumptionFile::in(Workspace::shardCoverage($shard));
    }

    /**
     * The covered files of these units: each unit's file, and every file
     * within a held path. A unit that is a file is found by its path, so the
     * verdict's map of every unit costs one pass over the map's files.
     */
    private function filesOf(Units $units, CoverageMap $map): Paths
    {
        $named = Paths::none();
        $held = [];

        foreach ($units as $unit) {
            $named = $named->with($unit->path());
            $held = $unit->isHeld() ? [...$held, $unit->path()] : $held;
        }

        $files = [];

        foreach ($map->files() as $file) {
            if ($named->has($file) || $this->withinAny($file, $held)) {
                $files[] = $file;
            }
        }

        return Paths::of(...$files);
    }

    /** @param list<Path> $paths */
    private function withinAny(Path $file, array $paths): bool
    {
        return array_any($paths, fn(Path $path): bool => $file->within($path));
    }
}
