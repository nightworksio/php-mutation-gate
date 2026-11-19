<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Control;

use function count;

use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * Where one unmutated control's time went, as a shard's measured steps hold
 * it (ADR-0167): what asked for it, how many tests it asked for and how many
 * its log names, the limit it was allowed, how long its process lasted and
 * how it ended.
 */
final readonly class ControlTime
{
    private function __construct(
        private AskingKinds $kinds,
        private int $tests,
        private int|NotGiven $cases,
        private Seconds $limit,
        private Seconds|Unmeasured $took,
        private ControlEnd $end,
    ) {
    }

    /** What a control asked for by these found as it ran. */
    public static function of(Control $control, ControlRun $run, ControlKind ...$kinds): self
    {
        return new self(
            AskingKinds::of(...$kinds),
            count($control->tests()),
            $run->cases(),
            $control->limit(),
            $run->wall(),
            $run->end(),
        );
    }

    /** A control's time as a result holds it. */
    public static function read(
        AskingKinds $kinds,
        int $tests,
        int|NotGiven $cases,
        Seconds $limit,
        Seconds|Unmeasured $took,
        ControlEnd $end,
    ): self {
        return new self($kinds, $tests, $cases, $limit, $took, $end);
    }

    public function kinds(): AskingKinds
    {
        return $this->kinds;
    }

    /** How many tests it asked for. */
    public function tests(): int
    {
        return $this->tests;
    }

    /** How many test cases its log names; not given where it kept none. */
    public function cases(): int|NotGiven
    {
        return $this->cases;
    }

    public function limit(): Seconds
    {
        return $this->limit;
    }

    /** How long its process lasted; unmeasured where it ran as no process of its own. */
    public function took(): Seconds|Unmeasured
    {
        return $this->took;
    }

    public function end(): ControlEnd
    {
        return $this->end;
    }
}
