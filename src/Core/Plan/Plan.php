<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_key_exists;
use function array_values;

use ArrayIterator;

use function count;

use Countable;
use IteratorAggregate;
use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Ci\RunOn;
use NightWorksIO\MutationGate\Core\File\Digest;
use NightWorksIO\MutationGate\Core\Proof\Keys;

use function sprintf;

use Traversable;

/**
 * The shards a run is cut into, made once on one commit and handed to every
 * job, with the content key of every unit it considered. A considered unit in
 * no shard is proved. A plan with no shards is a real answer: nothing is
 * reached, or everything is proved.
 *
 * @implements IteratorAggregate<int, Shard>
 */
final readonly class Plan implements Countable, IteratorAggregate
{
    /** @param array<int, Shard> $shards by number, in the order they were added */
    private function __construct(
        private Revision $commit,
        private RunOn $runOn,
        private Keys $keys,
        private array $shards,
    ) {
    }

    public static function of(Revision $commit, Keys $keys, Shards $shards): self
    {
        $numbered = [];

        foreach ($shards as $shard) {
            $numbered[$shard->id()->number()] = $shard;
        }

        return new self(
            $commit,
            RunOn::detached(CannotTell::because('The plan was made without asking what it runs on.')),
            $keys,
            $numbered,
        );
    }

    /** This plan, made for a run on this ref, which every shard and the verdict judge as. */
    public function on(RunOn $runOn): self
    {
        return clone($this, ['runOn' => $runOn]);
    }

    /** The commit the plan was made on. */
    public function commit(): Revision
    {
        return $this->commit;
    }

    /** The run's ref, whether it is a pull request, and the default branch, as the plan was made for them. */
    public function runOn(): RunOn
    {
        return $this->runOn;
    }

    /** The content key of every unit the plan considered, in a shard or proved. */
    public function keys(): Keys
    {
        return $this->keys;
    }

    /** What the plan says, as one digest, so a result can name the plan it followed. */
    public function digest(): Digest
    {
        return PlanFile::digestOf($this);
    }

    public function shard(ShardId $id): Shard|CannotJudge
    {
        return array_key_exists($id->number(), $this->shards)
            ? $this->shards[$id->number()]
            : CannotJudge::because(sprintf(
                'The plan has no shard %d. It holds %d shards, so this job was not planned from it.',
                $id->number(),
                count($this->shards),
            ));
    }

    /** This plan, where the checkout is the commit it was made on. A shard never runs another commit's plan. */
    public function forCheckout(Revision $head): self|CannotJudge
    {
        return $head->name() === $this->commit->name()
            ? $this
            : CannotJudge::because(sprintf(
                'The plan was made on %s, and this checkout is %s. Run a shard on the commit its plan was made on.',
                $this->commit->name(),
                $head->name(),
            ));
    }

    public function count(): int
    {
        return count($this->shards);
    }

    /** @return Traversable<int, Shard> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_values($this->shards));
    }
}
