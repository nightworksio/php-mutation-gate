<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Score\Percentage;

use function round;
use function sprintf;

/**
 * How a learned timeout multiple is read from a ledger's kills: `timeouts.learned`. It is what `percentile` of them
 * took no more than, kept between `least` and `most`.
 */
final readonly class LearnedBounds
{
    /** The share of the kills the multiple covers, where the config does not say. */
    private const float PERCENTILE = 0.99;

    /** The least it is learned as, where the config does not say. */
    private const float LEAST = 2.0;

    /** The most it is learned as, where the config does not say. */
    private const float MOST = 10.0;

    private const string PAST_THE_MOST = 'expected at most timeouts.learned.most, %s, got %s';

    private function __construct(
        private int|float|Absent $percentile,
        private int|float|Absent $least,
        private int|float|Absent $most,
    ) {
    }

    public static function of(
        int|float|Absent $percentile = new Absent(),
        int|float|Absent $least = new Absent(),
        int|float|Absent $most = new Absent(),
    ): self {
        return new self($percentile, $least, $most);
    }

    /** These bounds, or none set where there are none. */
    public static function orNone(self|Absent $bounds): self
    {
        return $bounds instanceof self ? $bounds : self::of();
    }

    /** Every key at the value it takes where every layer leaves it out. */
    public static function standard(): self
    {
        return new self(self::PERCENTILE, self::LEAST, self::MOST);
    }

    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->percentile, $later->percentile),
            Absent::laid($this->least, $later->least),
            Absent::laid($this->most, $later->most),
        );
    }

    public function percentile(): float
    {
        return $this->percentile instanceof Absent ? self::PERCENTILE : (float) $this->percentile;
    }

    public function least(): float
    {
        return $this->least instanceof Absent ? self::LEAST : (float) $this->least;
    }

    public function most(): float
    {
        return $this->most instanceof Absent ? self::MOST : (float) $this->most;
    }

    /** Why these bounds cannot hold, where the least is past the most. */
    public function refusal(): Invalid|Absent
    {
        return $this->least() > $this->most()
            ? Invalid::because(Problem::at(
                'timeouts.learned.least',
                sprintf(self::PAST_THE_MOST, $this->most(), $this->least()),
            ))
            : Absent::setting();
    }

    /** How many in a hundred kills the multiple covers, as a line says it. */
    public function inAHundred(): float
    {
        return round($this->percentile() * Percentage::MOST, precision: 2);
    }

    /** The `learned` member of `timeouts`; nothing where no key is set. */
    public function written(): Member
    {
        $keys = Json::object(
            Member::of('percentile', $this->percentile),
            Member::of('least', $this->least),
            Member::of('most', $this->most),
        );

        return Member::unlessEmpty('learned', $keys);
    }

    /** The builder's calls that set these keys, where a layer sets them. */
    public function php(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...$this->percentile instanceof Absent
                ? []
                : [sprintf('Timeouts::learnedPercentile(%s)', PhpCalls::literal($this->percentile))],
            ...$this->least instanceof Absent
                ? []
                : [sprintf('Timeouts::learnedLeast(%s)', PhpCalls::literal($this->least))],
            ...$this->most instanceof Absent
                ? []
                : [sprintf('Timeouts::learnedMost(%s)', PhpCalls::literal($this->most))],
        ]);
    }
}
