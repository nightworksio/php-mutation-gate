<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_slice;

use NightWorksIO\MutationGate\Core\Report\Commented;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;

/**
 * `survivorsFirst.max`: how many of the last run's survivors a pull request's
 * run re-checks before its shards, those on changed lines first; none at `0`
 * (ADR-0020, decision 19).
 */
final readonly class SurvivorsFirst
{
    private function __construct(private int $most)
    {
    }

    /** At most this many, a whole number of at least 0. */
    public static function atMost(int $most): self
    {
        return new self($most);
    }

    /** As many as the pull request comment lists. */
    public static function standard(): self
    {
        return new self(Commented::MOST);
    }

    public function most(): int
    {
        return $this->most;
    }

    /** Whether `0` turns the re-check off. */
    public function isOff(): bool
    {
        return $this->most === 0;
    }

    /**
     * The first of these survivors the pull request comment lists, those that
     * survived and those uncovered the score counts, as many as it re-checks:
     * never one left unjudged or found flaky.
     */
    public function taken(Survivors $survivors): Survivors
    {
        $listed = [];

        foreach ($survivors as $survivor) {
            $judgement = $survivor->judgement();
            $listed = $judgement === MutantJudgement::Survived || $judgement === MutantJudgement::Uncovered
                ? [...$listed, $survivor]
                : $listed;
        }

        return Survivors::of(...array_slice($listed, 0, $this->most));
    }
}
