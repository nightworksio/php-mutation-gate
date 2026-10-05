<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Recheck;

use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\Scoring;

/** One survivor of the last run, and what running it again found: the same mutant judged now, or none. */
final readonly class Recheck
{
    private function __construct(private JudgedMutant $before, private JudgedMutant|Gone $now)
    {
    }

    public static function found(JudgedMutant $before, JudgedMutant $now): self
    {
        return new self($before, $now);
    }

    public static function gone(JudgedMutant $before): self
    {
        return new self($before, Gone::value());
    }

    /** The survivor as the last run left it. */
    public function before(): JudgedMutant
    {
        return $this->before;
    }

    /** The same mutant as this run judged it; gone where it made none. */
    public function now(): JudgedMutant|Gone
    {
        return $this->now;
    }

    /** Whether it was found again, and the score would still count it as not killed. */
    public function stillSurvives(Uncovered $uncovered): bool
    {
        return $this->now instanceof JudgedMutant
            && $this->now->judgement()->scoring($uncovered) === Scoring::NotKilled;
    }
}
