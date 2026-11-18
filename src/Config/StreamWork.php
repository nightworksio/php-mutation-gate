<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Format\Json;

/** How the streams of a split-stream run get through their work (ADR-0182): the `splitStream` keys of speed alone. */
final readonly class StreamWork implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /**
     * `splitStream.parentDeadline`, as by default: a stream whose parent runs
     * a test past that test's deadline is stopped, and started again on the
     * tests it had not ended.
     */
    public static function parentDeadline(): self
    {
        return new self(Json::at('splitStream.parentDeadline', value: true));
    }

    /** `splitStream.parentDeadline` off: a stream runs each test for as long as it takes. */
    public static function noParentDeadline(): self
    {
        return new self(Json::at('splitStream.parentDeadline', value: false));
    }

    /**
     * `splitStream.deadlineBackoff`: the times its slowdown a stream started
     * in a stopped one's place starts at, over the slowdown the stopped one
     * reached; 2 by default, 1 to carry it on unchanged.
     */
    public static function deadlineBackoff(int|float $times): self
    {
        return new self(Json::at('splitStream.deadlineBackoff', value: $times));
    }

    /** `splitStream.slowdownCarry`, `shard`, as by default: a pass starts where the shard's last pass ended. */
    public static function slowdownThroughTheShard(): self
    {
        return new self(Json::at('splitStream.slowdownCarry', 'shard'));
    }

    /** `splitStream.slowdownCarry`, `run`: a pass's streams start where the run's last pass ended. */
    public static function slowdownThroughTheRun(): self
    {
        return new self(Json::at('splitStream.slowdownCarry', 'run'));
    }

    /** `splitStream.slowdownCarry`, `none`: every pass's streams start as fast as the map. */
    public static function slowdownPerPass(): self
    {
        return new self(Json::at('splitStream.slowdownCarry', 'none'));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
