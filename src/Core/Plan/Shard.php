<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function count;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Order\RiskOrder;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * The units one CI job mutates, all of one package, what they are expected
 * to cost, and the label the job is shown with. A shard of a fixed count may
 * be empty, and says so.
 */
final readonly class Shard
{
    private function __construct(
        private ShardId $id,
        private Package $package,
        private Units $units,
        private Seconds $cost,
        private string $label,
    ) {
    }

    public static function of(ShardId $id, Package $package, Units $units, Seconds $cost, string $label): self
    {
        return new self($id, $package, $units, $cost, $label);
    }

    /** A shard with nothing to mutate, which passes having run nothing. */
    public static function empty(ShardId $id): self
    {
        return new self($id, Package::at(Path::root()), Units::none(), Seconds::of(0.0), NothingToMutate::SAID);
    }

    public function id(): ShardId
    {
        return $this->id;
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
        return $this->cost;
    }

    /** The trees it takes, each with the part it is where a tree spans several shards. */
    public function label(): string
    {
        return $this->label;
    }

    /** The shard with its units in this order, the riskiest first (ADR-0008, decision 1). */
    public function ordered(RiskOrder $order): self
    {
        return new self($this->id, $this->package, $order->ordered($this->units), $this->cost, $this->label);
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
