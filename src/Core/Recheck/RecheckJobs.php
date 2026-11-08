<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Recheck;

use function ceil;
use function count;
use function min;

use NightWorksIO\MutationGate\Core\Config\SurvivorsFirst;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Format\JsonText;

use function range;

/**
 * The jobs a CI shares a re-check of the last run's survivors among
 * (ADR-0106): one per file of the survivors it takes, at most
 * `survivorsFirst.shards`, none where it takes none; and the minutes each
 * may take, its budget and the time a job takes to set up. As a matrix reads
 * it, on one line: `{"shares":[1,2],"minutes":30}`.
 *
 * @internal
 */
final readonly class RecheckJobs
{
    /** The minutes a re-check's job takes beyond its budget: checking out, installing and reading the plan. */
    public const int SETUP_MINUTES = 10;

    private function __construct(private int $count, private SurvivorsFirst $first)
    {
    }

    /** One job per file of the survivors a re-check takes, at most as many as `survivorsFirst.shards`. */
    public static function for(Paths $files, SurvivorsFirst $first): self
    {
        return new self(min(count($files), $first->shards()), $first);
    }

    /** No job, for a re-check that takes no survivor. */
    public static function none(SurvivorsFirst $first): self
    {
        return new self(0, $first);
    }

    /** One job, which says why, where which survivors a re-check takes cannot be told. */
    public static function unsure(SurvivorsFirst $first): self
    {
        return new self(1, $first);
    }

    public function count(): int
    {
        return $this->count;
    }

    public function encode(): string
    {
        return JsonText::compact([
            'shares' => $this->count === 0 ? [] : range(1, $this->count),
            'minutes' => (int) ceil($this->first->budget()->inMinutes()) + self::SETUP_MINUTES,
        ]);
    }
}
