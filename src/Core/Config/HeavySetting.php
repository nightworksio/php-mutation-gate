<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * `timeouts.heavyFill` and `timeouts.heavyParts` as a layer sets them
 * (ADR-0114): whether a round of heavy mutants' parts fills its pool with
 * parts ahead, and how many parts a heavy mutant is judged by before it is
 * left unjudged, none where it runs all of them.
 */
final readonly class HeavySetting
{
    private function __construct(private bool|Absent $fill, private int|Absent $parts)
    {
    }

    public static function of(bool|Absent $fill = new Absent(), int|Absent $parts = new Absent()): self
    {
        return new self($fill, $parts);
    }

    /** This, under a later layer: what the later sets, and this where it sets nothing. */
    public function over(self $later): self
    {
        return new self(Absent::laid($this->fill, $later->fill), Absent::laid($this->parts, $later->parts));
    }

    /** `timeouts.heavyFill`: whether a round of heavy mutants' parts fills its pool with parts ahead. */
    public function fills(): bool
    {
        return $this->fill instanceof Absent || $this->fill;
    }

    /** `timeouts.heavyParts`: the parts a heavy mutant is judged by at most; none where it runs all of them. */
    public function parts(): int|NotGiven
    {
        return is_int($this->parts) ? $this->parts : NotGiven::value();
    }

    /** This layer's `timeouts` with each key it sets in it. */
    public function written(Json $timeouts): Json
    {
        return $timeouts
            ->with(Member::of('heavyFill', $this->fill))
            ->with(Member::of('heavyParts', $this->parts));
    }

    /** The builder's calls that set it. */
    public function calls(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...match ($this->fill) {
                true => ['HeavyMutants::filling()'],
                false => ['HeavyMutants::notFilling()'],
                default => [],
            },
            ...is_int($this->parts) ? [sprintf('HeavyMutants::partsAtMost(%d)', $this->parts)] : [],
        ]);
    }
}
