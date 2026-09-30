<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Runner;

use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

/** An amount of memory as the gate's files write one: a whole number of bytes, more than none. */
final readonly class WrittenBytes
{
    /** @throws NotInShape */
    public static function read(Node $bytes): MemoryCap
    {
        return $bytes->integer() > 0
            ? MemoryCap::of($bytes->integer(), MemoryUnit::Bytes)
            : throw NotInShape::at($bytes->at(), 'a number of bytes');
    }
}
