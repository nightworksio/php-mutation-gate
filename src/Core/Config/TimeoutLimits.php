<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function intval;
use function max;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Runner\LimitBounds;
use NightWorksIO\MutationGate\Core\Runner\Multiple;
use NightWorksIO\MutationGate\Core\Runner\MutantLimit;
use NightWorksIO\MutationGate\Core\Runner\TighterSilence;
use NightWorksIO\MutationGate\Core\Time\Seconds;

use function sprintf;

/**
 * What each mutant's limit is kept between (ADR-0008): `timeouts.seconds`
 * and `timeouts.most`, the multiple of its covering tests' own time it
 * allows, `timeouts.multiple`, the mutators whose silence limit has a lower
 * floor, `timeouts.tighter`, what a run is allowed to start in,
 * `timeouts.startUp` and `timeouts.startUpMultiple`, and how long each test
 * of a run may run on its own, `timeouts.perTest` and `timeouts.testFloor`
 * (ADR-0090), and the most a kill's unmutated control may run,
 * `timeouts.controlMost` (ADR-0014).
 */
final readonly class TimeoutLimits
{
    /** The least and the most seconds a mutant may run. */
    private const int TIMEOUT = 10;

    private const int MOST = 300;

    /** The most a kill's unmutated control may run, in seconds, where no layer sets it. */
    private const int CONTROL_MOST = 900;

    private const string UNDER_THE_FLOOR = 'expected at least timeouts.seconds, %d, got %d';

    private const string UNDER_THE_MOST = 'expected at least timeouts.most, %d, got %d';

    private function __construct(
        private Seconds|Learned|Absent $limit,
        private Seconds|Absent $most,
        private int|float|Learned|Absent $multiple,
        private TighterSilence|Absent $tighter,
        private PerTestSetting $perTest,
        private Seconds|Absent $startUp,
        private int|float|Absent $startUpMultiple,
        private Seconds|Absent $controlMost,
    ) {
    }

    public static function of(
        Seconds|Learned|Absent $limit = new Absent(),
        Seconds|Absent $most = new Absent(),
        int|float|Learned|Absent $multiple = new Absent(),
        TighterSilence|Absent $tighter = new Absent(),
        int|false|Absent $perTest = new Absent(),
        Seconds|Absent $testFloor = new Absent(),
        Seconds|Absent $startUp = new Absent(),
        int|float|Absent $startUpMultiple = new Absent(),
        Seconds|Absent $controlMost = new Absent(),
    ): self {
        return new self(
            $limit,
            $most,
            $multiple,
            $tighter,
            PerTestSetting::of($perTest, $testFloor),
            $startUp,
            $startUpMultiple,
            $controlMost,
        );
    }

    /** These limits, with what a later layer sets laid over them. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->limit, $later->limit),
            Absent::laid($this->most, $later->most),
            Absent::laid($this->multiple, $later->multiple),
            Absent::laid($this->tighter, $later->tighter),
            $this->perTest->over($later->perTest),
            Absent::laid($this->startUp, $later->startUp),
            Absent::laid($this->startUpMultiple, $later->startUpMultiple),
            Absent::laid($this->controlMost, $later->controlMost),
        );
    }

    /**
     * `timeouts.seconds`: the least one mutant's run is allowed, and what one
     * whose tests are not timed is; where it is learned, what it is before a
     * start-up is measured.
     */
    public function limit(): Seconds
    {
        return $this->limit instanceof Seconds ? $this->limit : Seconds::of(self::TIMEOUT);
    }

    /** `timeouts.most`: the most one mutant's run is allowed, and Infection's skip for tests that take as long. */
    public function most(): Seconds
    {
        return $this->most instanceof Seconds ? $this->most : Seconds::of(self::MOST);
    }

    /**
     * `timeouts.controlMost`: the most a kill's unmutated control is allowed
     * (ADR-0014), which runs the tests its mutant's run selected, and the
     * selection a run that left its filter out loaded can take far longer
     * than the tests that failed in it; where no layer sets it, 900 s, or
     * `timeouts.most` where that is longer.
     */
    public function controlMost(): Seconds
    {
        return $this->controlMost instanceof Seconds
            ? $this->controlMost
            : Seconds::of(max(self::CONTROL_MOST, $this->most()->seconds()));
    }

    /** Why these limits cannot be laid, a most under the floor or a control's most under the most; or nothing. */
    public function refusal(): Invalid|Absent
    {
        $floor = intval($this->limit()->seconds());
        $most = intval($this->most()->seconds());
        $controls = intval($this->controlMost()->seconds());

        return match (true) {
            $most < $floor => Invalid::because(
                Problem::at('timeouts.most', sprintf(self::UNDER_THE_FLOOR, $floor, $most)),
            ),
            $controls < $most => Invalid::because(
                Problem::at('timeouts.controlMost', sprintf(self::UNDER_THE_MOST, $most, $controls)),
            ),
            default => new Absent(),
        };
    }

    /** What each mutant's limit is kept between, as these settings say. */
    public function bounds(): LimitBounds
    {
        $startUp = $this->startUp instanceof Seconds ? $this->startUp->seconds() : MutantLimit::START_UP;
        $multiple = $this->startUpMultiple instanceof Absent ? MutantLimit::FACTOR : $this->startUpMultiple;
        $bounds = LimitBounds::between($this->limit(), $this->most(), $startUp, $multiple)
            ->tighterFor($this->tighter instanceof TighterSilence ? $this->tighter : TighterSilence::standard())
            ->multipliedBy(match (true) {
                $this->multiple instanceof Absent => Multiple::standard(),
                $this->multiple instanceof Learned => Learned::Learned,
                default => Multiple::of($this->multiple),
            })
            ->watchingEachTest($this->perTest->limit());

        return $this->limit instanceof Learned ? $bounds->learningFloor() : $bounds;
    }

    /** The keys of `timeouts` a layer sets of these, as it writes them. */
    public function written(): Json
    {
        return Json::object(...[
            Member::of('seconds', LimitCalls::secondsWritten($this->limit)),
            Member::of('most', WholeSeconds::written($this->most)),
            Member::of('multiple', LimitCalls::multipleWritten($this->multiple)),
            Member::of('startUp', WholeSeconds::duration($this->startUp)),
            Member::of('startUpMultiple', $this->startUpMultiple),
            Member::of('tighter', LimitCalls::tighterWritten($this->tighter)),
            Member::of('controlMost', WholeSeconds::written($this->controlMost)),
            ...$this->perTest->written(),
        ]);
    }

    /** The builder's calls that set these as a layer sets them. */
    public function php(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...LimitCalls::seconds($this->limit),
            ...LimitCalls::multiple($this->multiple),
            ...LimitCalls::writtenSeconds('Timeouts::startUp', $this->startUp),
            ...LimitCalls::startUpMultiple($this->startUpMultiple),
            ...LimitCalls::wholeSeconds('Timeouts::most', $this->most),
            ...LimitCalls::tighter($this->tighter),
            ...$this->perTest->calls(),
            ...LimitCalls::wholeSeconds('Timeouts::controlMost', $this->controlMost),
        ]);
    }
}
