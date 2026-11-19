<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function count;

use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Cost\CostBasis;
use NightWorksIO\MutationGate\Core\Cost\Estimated;
use NightWorksIO\MutationGate\Core\Cost\ShardEstimate;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Order\RiskOrder;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Test\TestPart;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * The units one CI job mutates, all of one package, what they are expected
 * to cost, the label the job is shown with, and the part of the test files
 * whose tests judge them: every test file, or one part of them where the run
 * cuts its test files across its shards (ADR-0117). A shard of a fixed count
 * may be empty, and says so.
 */
final readonly class Shard
{
    private function __construct(
        private ShardId $id,
        private Package $package,
        private Units $units,
        private ShardEstimate $estimate,
        private string $label,
        private TestPart $part,
        private ShardId|Absent $worker = new Absent(),
    ) {
    }

    /** A shard whose units are expected to take this long, on no measurement. */
    public static function of(ShardId $id, Package $package, Units $units, Seconds $cost, string $label): self
    {
        return new self(
            $id,
            $package,
            $units,
            ShardEstimate::none()->with(Estimated::of($cost, CostBasis::Guessed)),
            $label,
            TestPart::whole(),
        );
    }

    /** A shard with nothing to mutate, which passes having run nothing. */
    public static function empty(ShardId $id): self
    {
        return new self(
            $id,
            Package::at(Path::root()),
            Units::none(),
            ShardEstimate::none(),
            NothingToMutate::SAID,
            TestPart::whole(),
        );
    }

    /** This shard, expected to take what this estimate says, which says what it rests on. */
    public function estimated(ShardEstimate $estimate): self
    {
        return new self($this->id, $this->package, $this->units, $estimate, $this->label, $this->part, $this->worker);
    }

    /** This shard, its units judged by the tests of this part of the test files alone (ADR-0117). */
    public function inPart(TestPart $part): self
    {
        return new self($this->id, $this->package, $this->units, $this->estimate, $this->label, $part, $this->worker);
    }

    /**
     * The same shard, with these of its units alone, expected to cost what it
     * was, on no measurement, in the same part of the test files.
     */
    public function holding(Units $units): self
    {
        return self::of($this->id, $this->package, $units, $this->cost(), $this->label)->inPart($this->part)
            ->workedBy($this->worker);
    }

    /**
     * This shard as an item of a run whose shards pull their work, listed
     * first for this shard's job (ADR-0134); its own job's where none is.
     */
    public function workedBy(ShardId|Absent $worker): self
    {
        return new self($this->id, $this->package, $this->units, $this->estimate, $this->label, $this->part, $worker);
    }

    public function id(): ShardId
    {
        return $this->id;
    }

    /** The job whose list holds it first: its own, unless the run's shards pull their work (ADR-0134). */
    public function worker(): ShardId
    {
        return $this->worker instanceof ShardId ? $this->worker : $this->id;
    }

    /** Whether it is an item of a run whose shards pull their work (ADR-0134). */
    public function isPulled(): bool
    {
        return $this->worker instanceof ShardId;
    }

    /** The package the shard runs in, whose directory the runner starts from. */
    public function package(): Package
    {
        return $this->package;
    }

    public function units(): Units
    {
        return $this->units;
    }

    /** What the cost model expects mutating its units to take. */
    public function cost(): Seconds
    {
        return $this->estimate->units();
    }

    /** What the plan expects of the shard, what that rests on, and its opening run. */
    public function estimate(): ShardEstimate
    {
        return $this->estimate;
    }

    /** The trees it takes, each with the part it is where a tree spans several shards. */
    public function label(): string
    {
        return $this->label;
    }

    /** The part of the test files whose tests judge its units: every test file, unless the run cuts them. */
    public function testPart(): TestPart
    {
        return $this->part;
    }

    /** The shard with its units in this order, the riskiest first (ADR-0008). */
    public function ordered(RiskOrder $order): self
    {
        return new self(
            $this->id,
            $this->package,
            $order->ordered($this->units),
            $this->estimate,
            $this->label,
            $this->part,
            $this->worker,
        );
    }

    public function isEmpty(): bool
    {
        return count($this->units) === 0;
    }

    public function invocations(): Invocations
    {
        return Invocations::of($this->units);
    }
}
