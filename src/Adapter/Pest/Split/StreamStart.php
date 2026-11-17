<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest\Split;

/**
 * Where one stream of a pass starts (ADR-0182): its pass, its place among
 * the pass's streams and how many places one round of them takes, the
 * sites it forks at, the map's times, the cores, and the arguments that
 * select the pass's tests.
 *
 * @internal
 */
final readonly class StreamStart
{
    /**
     * @param array<string, array<int, array{string, string, array<string, string>}>> $planned
     * @param array<string, float>                                                    $times
     * @param list<string>                                                            $selecting
     */
    public function __construct(
        public int $pass,
        public int $at,
        public int $round,
        public array $planned,
        public array $times,
        public int $cores,
        public array $selecting,
    ) {
    }

    /** The start of a stream in this one's place, one round of streams on, so its files are its own. */
    public function next(): self
    {
        return new self(
            $this->pass,
            $this->at + $this->round,
            $this->round,
            $this->planned,
            $this->times,
            $this->cores,
            $this->selecting,
        );
    }
}
