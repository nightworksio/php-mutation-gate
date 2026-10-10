<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Test\JudgingSuites;
use NightWorksIO\MutationGate\Core\Test\Suites;

use function sprintf;

/**
 * `tests.suites` and `tests.holding` as a layer writes them (ADR-0002,
 * decision 8): the names of the suites whose tests judge every unit, and of
 * those whose tests judge only the units they hold, each where it is set.
 */
final readonly class SuiteLists
{
    /**
     * @param Listed<string>|Absent $judging
     * @param Listed<string>|Absent $holding
     */
    private function __construct(private Listed|Absent $judging, private Listed|Absent $holding)
    {
    }

    /**
     * @param Listed<string>|Absent $judging
     * @param Listed<string>|Absent $holding
     */
    public static function of(Listed|Absent $judging = new Absent(), Listed|Absent $holding = new Absent()): self
    {
        return new self($judging, $holding);
    }

    /** Each list a later layer sets in place of this one's. */
    public function over(self $later): self
    {
        return new self(Absent::laid($this->judging, $later->judging), Absent::laid($this->holding, $later->holding));
    }

    /** The suites they name; every suite judging every unit where neither is set. */
    public function suites(): JudgingSuites
    {
        $judging = Suites::listed(...$this->judging instanceof Listed ? $this->judging : []);

        return $this->holding instanceof Listed
            ? JudgingSuites::holding($judging, Suites::listed(...$this->holding))
            : JudgingSuites::judging($judging);
    }

    /** `tests.suites` as a config writes it, where it is set. */
    public function judgingWritten(): Member
    {
        return Member::of('suites', $this->judging instanceof Listed ? Json::items(...$this->judging) : $this->judging);
    }

    /** `tests.holding` as a config writes it, where it is set. */
    public function holdingWritten(): Member
    {
        return Member::of(
            'holding',
            $this->holding instanceof Listed ? Json::items(...$this->holding) : $this->holding,
        );
    }

    /** The builder's calls for each list that is set, in a config written as PHP. */
    public function calls(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->judging instanceof Listed
                ? [sprintf('Tests::suites(%s)', PhpCalls::literals(...$this->judging))]
                : [],
            ...$this->holding instanceof Listed
                ? [sprintf('Tests::holding(%s)', PhpCalls::literals(...$this->holding))]
                : [],
        ]);
    }
}
