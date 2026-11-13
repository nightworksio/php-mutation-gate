<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\NotGiven;

use function sprintf;

/**
 * How many rounds `watch` runs before it ends, and the map its first round
 * measures against, carried from an earlier measure (ADR-0146): keys of
 * `local`.
 */
final readonly class LocalRounds
{
    private function __construct(private int|Absent $rounds, private Path|Absent $fromMap)
    {
    }

    public static function of(int|Absent $rounds = new Absent(), Path|Absent $fromMap = new Absent()): self
    {
        return new self($rounds, $fromMap);
    }

    /** These settings, with what a later layer sets laid over them. */
    public function over(self $later): self
    {
        return new self(Absent::laid($this->rounds, $later->rounds), Absent::laid($this->fromMap, $later->fromMap));
    }

    /** `local.rounds`: how many rounds `watch` runs before it ends; none where it runs until it is stopped. */
    public function rounds(): int|NotGiven
    {
        return $this->rounds instanceof Absent ? NotGiven::value() : $this->rounds;
    }

    /**
     * `local.fromMap`: the directory of a map an earlier `coverage --into`
     * wrote, which the first round of `watch` measures against as a quick
     * round does against the round before; none where the first round
     * measures every test.
     */
    public function fromMap(): Path|NotGiven
    {
        return $this->fromMap instanceof Path ? $this->fromMap : NotGiven::value();
    }

    /** The keys of `local` a layer sets of these, as it writes them. */
    public function written(): Json
    {
        return Json::object(
            Member::of('rounds', $this->rounds),
            Member::of('fromMap', $this->fromMap instanceof Path ? $this->fromMap->value() : $this->fromMap),
        );
    }

    /** The builder's calls that set these as a layer sets them. */
    public function php(): PhpCalls
    {
        return PhpCalls::inWith(
            ...$this->rounds instanceof Absent ? [] : [sprintf('Local::rounds(%d)', $this->rounds)],
        )->and(PhpCalls::inWith(
            ...$this->fromMap instanceof Path
                ? [sprintf('Local::fromMap(%s)', PhpCalls::literal($this->fromMap->value()))]
                : [],
        ));
    }
}
