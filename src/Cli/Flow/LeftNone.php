<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Flow;

use function array_map;
use function array_pop;
use function count;
use function implode;

use NightWorksIO\MutationGate\Adapter\Filesystem\Directory;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Plan\Shard;
use NightWorksIO\MutationGate\Core\Plan\ShardEnd;
use NightWorksIO\MutationGate\Core\Plan\ShardEndRecord;

use function sprintf;

/**
 * The shards of a plan that left no result in a directory of results, each
 * with how the CI said its job ended, where it said so beside where the
 * result would be: why their mutants cannot be judged, one by its file,
 * several together by their directory, each the CI said the end of by how
 * its job ended, and the others by what leaves none.
 *
 * @internal
 */
final readonly class LeftNone
{
    private const string ONE = 'Shard %d (%s) left no result at %s, so its mutants cannot be judged.';

    private const string MANY = 'Shards %s left no result in %s, so their mutants cannot be judged.';

    private const string UNTOLD = 'A shard that crashed, was cancelled or never started leaves none.';

    private const string AGAIN = '%s Run it again.';

    private const string AGAIN_MANY = '%s Run them again.';

    /** How the CI said a shard's job ended: its number and label, and what ended it. */
    private const string ENDED = 'Shard %d (%s): %s.';

    /** A shard as a list of them names it: its number, and its label. */
    private const string NAMED = '%d (%s)';

    /** @param list<array{Shard, Path, ShardEnd|NotGiven}> $missing each shard, where its result would be, its end */
    private function __construct(private Path $directory, private array $missing)
    {
    }

    /** None yet, in this directory of results. */
    public static function in(Path $directory): self
    {
        return new self($directory, []);
    }

    /** This, and a shard that left no result at this file, with how the project's CI said its job ended. */
    public function with(Shard $shard, Path $file, Directory $project): self
    {
        $said = $project->read(Workspace::ended($this->directory, $shard->id()));
        $end = $said instanceof Contents ? ShardEndRecord::decode($said->text()) : NotGiven::value();

        return new self($this->directory, [...$this->missing, [$shard, $file, $end]]);
    }

    /** @return list<Shard> */
    public function shards(): array
    {
        return array_map(static fn(array $left): Shard => $left[0], $this->missing);
    }

    /**
     * Why their mutants cannot be judged; nothing where every shard left a result.
     *
     * @return list<string>
     */
    public function why(): array
    {
        if ($this->missing === []) {
            return [];
        }

        $lines = [$this->heading()];
        $untold = false;

        foreach ($this->missing as [$shard, , $end]) {
            $untold = $untold || $end instanceof NotGiven;
            $lines = $end instanceof ShardEnd
                ? [...$lines, sprintf(self::ENDED, $shard->id()->number(), $shard->label(), $end->why())]
                : $lines;
        }

        $lines = $untold ? [...$lines, self::UNTOLD] : $lines;
        $last = array_pop($lines);

        $again = count($this->missing) === 1 ? self::AGAIN : self::AGAIN_MANY;

        return [implode("\n", [...$lines, sprintf($again, $last)])];
    }

    /** Which shards left none: one by its file, several by their directory. */
    private function heading(): string
    {
        [$shard, $file] = $this->missing[0];
        $named = array_map(
            static fn(array $left): string => sprintf(self::NAMED, $left[0]->id()->number(), $left[0]->label()),
            $this->missing,
        );

        return count($this->missing) === 1
            ? sprintf(self::ONE, $shard->id()->number(), $shard->label(), $file->value())
            : sprintf(self::MANY, implode(', ', $named), $this->directory->value());
    }
}
