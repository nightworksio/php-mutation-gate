<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

use function sprintf;

/**
 * How far a run's split streams have come, for the gate's log: how many
 * mutants a child decided so far, the pass the streams are in, how many
 * streams that pass started, and how many still run; none where the run
 * streams nothing now.
 *
 * @internal
 */
final readonly class StreamsSeen
{
    private const string PHASE = 'streams pass %d: %d of %d running';

    public function __construct(public int $decided, private int $pass, private int $streams, private int $running)
    {
    }

    /** No stream runs, and none decided anything. */
    public static function none(): self
    {
        return new self(0, 0, 0, 0);
    }

    /** Whether the run streams now. */
    public function areStreaming(): bool
    {
        return $this->pass > 0;
    }

    /** The pass, and how many of its streams still run; nothing where the run streams nothing now. */
    public function phase(): string
    {
        return $this->areStreaming() ? sprintf(self::PHASE, $this->pass, $this->running, $this->streams) : '';
    }
}
