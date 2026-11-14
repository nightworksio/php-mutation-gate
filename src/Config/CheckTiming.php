<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Config;

use NightWorksIO\MutationGate\Core\Config\TestsTime;
use NightWorksIO\MutationGate\Core\Format\Json;

/**
 * When static analysis checks a mutant (ADR-0020): before its tests or after
 * them, the tests' time it weighs a check against, how many checks pass
 * before checking before the tests pays, and whether it checks the
 * survivors after: keys of `staticCheck`.
 */
final readonly class CheckTiming implements Setting
{
    private function __construct(private Json $json)
    {
    }

    /** `staticCheck.before`, as by default: a mutant is also checked before its tests, where that pays. */
    public static function beforeTests(): self
    {
        return new self(Json::at('staticCheck.before', value: true));
    }

    /** `staticCheck.before` off: only the tests' survivors are checked. */
    public static function afterTests(): self
    {
        return new self(Json::at('staticCheck.before', value: false));
    }

    /** `staticCheck.testsTime`, as by default: a check is weighed against what the ledgers learned judging costs. */
    public static function learnedTestsTime(): self
    {
        return new self(Json::at('staticCheck.testsTime', TestsTime::Learned->value));
    }

    /** `staticCheck.testsTime: covering`: a check is weighed against every covering test's own time. */
    public static function coveringTestsTime(): self
    {
        return new self(Json::at('staticCheck.testsTime', TestsTime::Covering->value));
    }

    /** `staticCheck.preCheckAfter`: how many checks of a mutator come before its rate decides, 50 by default. */
    public static function preCheckAfter(int $checks): self
    {
        return new self(Json::at('staticCheck.preCheckAfter', $checks));
    }

    /**
     * `staticCheck.after`, `true`: a survivor the analyser rejects counts as killed by static analysis, as by default.
     */
    public static function checkingSurvivors(): self
    {
        return new self(Json::at('staticCheck.after', value: true));
    }

    /** `staticCheck.after`, `false`: no survivor is checked after its tests. */
    public static function survivorsUnchecked(): self
    {
        return new self(Json::at('staticCheck.after', value: false));
    }

    public function written(): Json
    {
        return $this->json;
    }
}
