<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\Learned;
use NightWorksIO\MutationGate\Core\Config\TimeoutMode;
use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;

/** How timeouts are judged (ADR-0008): `timeouts`. */
final readonly class Timeouts implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** A timeout is a kill when its limit allowed the covering tests their multiple of their own time (ADR-0008). */
    public static function confirmed(): self
    {
        return new self(Json::at('timeouts.mode', TimeoutMode::Confirm->value));
    }

    /** A timeout is always too slow to judge. */
    public static function unjudged(): self
    {
        return new self(Json::at('timeouts.mode', TimeoutMode::Unjudged->value));
    }

    /** `timeouts.headroom`: the share of its limit a timed-out mutant's tests leave unused to be a kill (ADR-0108). */
    public static function headroom(int|float $share): self
    {
        return new self(Json::at('timeouts.headroom', $share));
    }

    /** `timeouts.seconds` */
    public static function seconds(int $seconds): self
    {
        return new self(Json::at('timeouts.seconds', $seconds));
    }

    /** `timeouts.seconds: "learned"`: three times each shard's measured start-up, at least a second (ADR-0073). */
    public static function learnedSeconds(): self
    {
        return new self(Json::at('timeouts.seconds', Learned::Learned->value));
    }

    /** `timeouts.multiple`: how many times its covering tests' own time each mutant is allowed. */
    public static function multiple(int|float $multiple): self
    {
        return new self(Json::at('timeouts.multiple', $multiple));
    }

    /** `timeouts.multiple: "learned"`: what 99 in 100 of the ledger's kills took, between 2 and 10 (ADR-0073). */
    public static function learnedMultiple(): self
    {
        return new self(Json::at('timeouts.multiple', Learned::Learned->value));
    }

    /**
     * `timeouts.controlMost`: the most a kill's unmutated control may run, in
     * seconds, at least `timeouts.most` (ADR-0014).
     */
    public static function controlMost(int $seconds): self
    {
        return new self(Json::at('timeouts.controlMost', $seconds));
    }

    /** `timeouts.most` */
    public static function most(int $seconds): self
    {
        return new self(Json::at('timeouts.most', $seconds));
    }

    /** `timeouts.perTest`: each test of a run may run this many times its own time (ADR-0090). */
    public static function perTest(int $multiple): self
    {
        return new self(Json::at('timeouts.perTest', $multiple));
    }

    /** `timeouts.perTest` off: no test of a run is held to a limit of its own. */
    public static function noTestLimit(): self
    {
        return new self(Json::at('timeouts.perTest', value: false));
    }

    /** `timeouts.learned.percentile`: the share of a ledger's kills a learned multiple covers, 0.99 by default. */
    public static function learnedPercentile(int|float $share): self
    {
        return new self(Json::at('timeouts.learned.percentile', $share));
    }

    /** `timeouts.learned.least`: the least a learned multiple is, 2 by default. */
    public static function learnedLeast(int|float $multiple): self
    {
        return new self(Json::at('timeouts.learned.least', $multiple));
    }

    /** `timeouts.learned.most`: the most a learned multiple is, 10 by default. */
    public static function learnedMost(int|float $multiple): self
    {
        return new self(Json::at('timeouts.learned.most', $multiple));
    }

    /** `timeouts.startUp`: what a mutant's run allows for start-up where none was measured. */
    public static function startUp(string $duration): self
    {
        return new self(Json::at('timeouts.startUp', $duration));
    }

    /**
     * `timeouts.startUpMultiple`: how many times a run of no test's measured start-up a mutant's run allows to start.
     */
    public static function startUpMultiple(int|float $times): self
    {
        return new self(Json::at('timeouts.startUpMultiple', $times));
    }

    /** `timeouts.testFloor`: the least each test of a run may run, in seconds, where `timeouts.perTest` holds it. */
    public static function testFloor(int $seconds): self
    {
        return new self(Json::at('timeouts.testFloor', $seconds));
    }

    /**
     * `timeouts.tighter`: the silence limit of a mutant of these mutators,
     * each by its short name, the last part of the name a runner gives it,
     * is kept above this floor in seconds instead of `timeouts.seconds`.
     */
    public static function tighter(int $floor, string ...$mutators): self
    {
        $tighter = Json::object(
            Member::of('mutators', Json::items(...$mutators)),
            Member::of('floor', $floor),
        );

        return new self(Json::at('timeouts.tighter', $tighter));
    }

    /**
     * `timeouts.parts`, `true`: a mutant whose tests cannot fit `timeouts.most` in one run is judged in parts, as by
     * default.
     */
    public static function inParts(): self
    {
        return new self(Json::at('timeouts.parts', value: true));
    }

    /** `timeouts.parts`, `false`: such a mutant is unjudged rather than judged in parts. */
    public static function neverInParts(): self
    {
        return new self(Json::at('timeouts.parts', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
