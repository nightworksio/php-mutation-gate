<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_filter;
use function array_key_exists;
use function array_values;
use function count;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\Mutants;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;

/**
 * What the records of a run say of its kills: the own run each mutant's
 * process recorded, with its copy and the seconds Pest allowed it, and which
 * mutants Pest left uncovered, whose kills are the trial's (ADR-0004).
 * Records that cannot be read say nothing of any.
 *
 * @internal
 */
final readonly class RecordedKills
{
    /**
     * @param array<string, array{OwnRun, string, Seconds|Unmeasured}> $runs      by native id
     * @param array<string, true>                                      $uncovered each native id Pest left uncovered
     */
    private function __construct(private array $runs, private array $uncovered)
    {
    }

    public static function of(Records|CannotJudge $records): self
    {
        if (! $records instanceof Records) {
            return new self([], []);
        }

        $runs = [];
        $uncovered = [];

        foreach ($records->planned() as $planned) {
            $runs[$planned->id()] = [
                $records->runOf($planned),
                $planned->mutated()->value(),
                $records->limitOf($planned),
            ];

            if ($records->statusOf($planned) === PestStatus::Uncovered) {
                $uncovered[$planned->id()] = true;
            }
        }

        return new self($runs, $uncovered);
    }

    /**
     * The kills of mutants Pest covered, which a narrowed run's control judges.
     *
     * @return list<Mutant>
     */
    public function judged(Mutants $mutants): array
    {
        return array_values(array_filter(
            [...$mutants],
            fn(Mutant $mutant): bool => $mutant->status() === MutantStatus::Killed
                && ! array_key_exists($mutant->nativeId(), $this->uncovered),
        ));
    }

    /**
     * What a kill's own run recorded, its copy and the seconds Pest allowed
     * it; nothing where the records cannot vouch for the kill, as they hold
     * no run of it or name no test as its killer.
     *
     * @return array{OwnRun, string, Seconds|Unmeasured}|NotGiven
     */
    public function vouchedFor(Mutant $mutant): array|NotGiven
    {
        return array_key_exists($mutant->nativeId(), $this->runs) && count($mutant->killers()) > 0
            ? $this->runs[$mutant->nativeId()]
            : NotGiven::value();
    }
}
