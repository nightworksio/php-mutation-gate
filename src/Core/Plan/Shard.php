<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use NightWorksIO\MutationGate\Core\Unit\Units;

/** The units one CI job mutates. */
final readonly class Shard
{
    private function __construct(private ShardId $id, private Units $units) {}

    public static function of(ShardId $id, Units $units): self
    {
        return new self($id, $units);
    }

    public function id(): ShardId
    {
        return $this->id;
    }

    public function units(): Units
    {
        return $this->units;
    }
}
