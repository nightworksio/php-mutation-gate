<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_map;

use NightWorksIO\MutationGate\Core\Analysis\Unchecked;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

use function sprintf;

/**
 * What a pull request's shard does once one of its confirmed survivors leaves
 * it no way to pass, `run.failFast`, and which survivors static analysis left
 * unchecked it waits on rather than counting, since a check may still kill
 * them, `run.failFastWaits` (ADR-0008, ADR-0103).
 */
final readonly class FailingFast
{
    /** @param Listed<Unchecked>|Absent $waits */
    private function __construct(private FailFast|Absent $mode, private Listed|Absent $waits)
    {
    }

    /** @param Listed<Unchecked>|Absent $waits */
    public static function of(FailFast|Absent $mode = new Absent(), Listed|Absent $waits = new Absent()): self
    {
        return new self($mode, $waits);
    }

    public function over(self $later): self
    {
        return new self(Absent::laid($this->mode, $later->mode), Absent::laid($this->waits, $later->waits));
    }

    /** `run.failFast`: by default the shard judges every unit and then fails. */
    public function mode(): FailFast
    {
        return $this->mode instanceof FailFast ? $this->mode : FailFast::Mark;
    }

    /**
     * `run.failFastWaits`: by default, the survivors whose check ran out of time or could not run.
     *
     * @return Listed<Unchecked>
     */
    public function waits(): Listed
    {
        return $this->waits instanceof Listed ? $this->waits : Listed::of(Unchecked::OutOfTime, Unchecked::Failed);
    }

    /** The members of `run` it writes, those set. */
    public function written(): Json
    {
        return Json::object(
            Member::of('failFast', $this->mode instanceof FailFast ? $this->mode->value : $this->mode),
            Member::of(
                'failFastWaits',
                $this->waits instanceof Listed ? Json::items(...$this->outcomes($this->waits)) : $this->waits,
            ),
        );
    }

    /** The builder's calls for the keys it sets. */
    public function php(): PhpCalls
    {
        return PhpCalls::inWith(
            ...$this->mode instanceof FailFast ? [$this->mode->php()] : [],
            ...$this->waits instanceof Listed
                ? [sprintf('Reach::failFastWaitingOn(%s)', PhpCalls::literals(...$this->outcomes($this->waits)))]
                : [],
        );
    }

    /**
     * @param  Listed<Unchecked> $waits
     * @return list<string>      each as a config writes it
     */
    private function outcomes(Listed $waits): array
    {
        return array_map(static fn(Unchecked $why): string => $why->value, [...$waits]);
    }
}
