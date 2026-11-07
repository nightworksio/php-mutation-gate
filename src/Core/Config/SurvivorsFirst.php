<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Config;

use function array_slice;
use function is_int;

use NightWorksIO\MutationGate\Core\Format\Json;
use NightWorksIO\MutationGate\Core\Format\Member;
use NightWorksIO\MutationGate\Core\Report\Commented;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\Survivors;

use function sprintf;

/**
 * How a pull request's run re-checks the last run's survivors before its
 * shards (ADR-0020, ADR-0106): `survivorsFirst.max`, how many, those on
 * changed lines first, none at `0`; `survivorsFirst.budget`, the time the
 * re-check spends at most, a file it has no time left for not run; and
 * `survivorsFirst.shards`, how many jobs share it at most, each taking its
 * share of the files. Each judges or reports only.
 */
final readonly class SurvivorsFirst
{
    /** The time a re-check spends at most by default: two thirds of the job the reusable workflow gives it. */
    public const int BUDGET_MINUTES = 20;

    /** How many jobs share a re-check at most by default. */
    public const int SHARDS = 4;

    /** The most jobs a re-check can be shared among, as many as GitHub runs at once on its free plan. */
    public const int MOST_SHARDS = 20;

    private function __construct(private int|Absent $most, private Seconds|Absent $budget, private int|Absent $shards)
    {
    }

    public static function of(
        int|Absent $most = new Absent(),
        Seconds|Absent $budget = new Absent(),
        int|Absent $shards = new Absent(),
    ): self {
        return new self($most, $budget, $shards);
    }

    /** At most this many, a whole number of at least 0. */
    public static function atMost(int $most): self
    {
        return new self($most, new Absent(), new Absent());
    }

    /** As many as the pull request comment lists, within the default budget and shards. */
    public static function standard(): self
    {
        return new self(new Absent(), new Absent(), new Absent());
    }

    /** This, with every key set to the value it takes. */
    public function resolved(): self
    {
        return new self($this->most(), $this->budget(), $this->shards());
    }

    /** This, with what a later layer sets laid over it, key by key. */
    public function over(self $later): self
    {
        return new self(
            Absent::laid($this->most, $later->most),
            Absent::laid($this->budget, $later->budget),
            Absent::laid($this->shards, $later->shards),
        );
    }

    public function most(): int
    {
        return is_int($this->most) ? $this->most : Commented::MOST;
    }

    /** `survivorsFirst.budget`: the time the re-check spends at most. */
    public function budget(): Seconds
    {
        return $this->budget instanceof Seconds ? $this->budget : Seconds::minutes(self::BUDGET_MINUTES);
    }

    /** `survivorsFirst.shards`: how many jobs share the re-check at most. */
    public function shards(): int
    {
        return is_int($this->shards) ? $this->shards : self::SHARDS;
    }

    /** Whether `0` turns the re-check off. */
    public function isOff(): bool
    {
        return $this->most() === 0;
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

        return Survivors::of(...array_slice($listed, 0, $this->most()));
    }

    /** `survivorsFirst` as a config writes it, each key where it is set. */
    public function written(): Json
    {
        return Json::object(
            Member::of('max', $this->most),
            Member::of('budget', $this->budget instanceof Seconds ? $this->budget->written() : $this->budget),
            Member::of('shards', $this->shards),
        );
    }

    /** The builder's calls for the keys set, in a config written as PHP. */
    public function calls(): PhpCalls
    {
        return PhpCalls::inWith(...[
            ...is_int($this->most) ? [sprintf('Survivors::firstAtMost(%d)', $this->most)] : [],
            ...$this->budget instanceof Seconds
                ? [sprintf('Survivors::firstWithin(%s)', PhpCalls::literal($this->budget->written()))]
                : [],
            ...is_int($this->shards) ? [sprintf('Survivors::firstAcross(%d)', $this->shards)] : [],
        ]);
    }
}
