<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\Analysis\Rejection;
use NightWorksIO\MutationGate\Core\Cluster\Membership;
use NightWorksIO\MutationGate\Core\Cluster\Unclustered;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantRecord;
use NightWorksIO\MutationGate\Core\Mutant\ProvedKill;
use NightWorksIO\MutationGate\Core\Mutant\Reason;
use NightWorksIO\MutationGate\Core\Removal\Removable;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Verdict\JudgedKill;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\NoFinding;

/**
 * One mutant as the JSON report and `explain --json` write it: where it is,
 * what it changed, how it was judged and why, the tests that cover, judged
 * and killed it, and what applies only to some (ADR-0009, decision 2).
 *
 * @phpstan-import-type RejectionWritten from MutantRecord
 *
 * @phpstan-type MutantEntry array{
 *     id: string,
 *     file: string,
 *     line: int,
 *     end?: int,
 *     mutator: string,
 *     family?: string,
 *     diff?: string,
 *     status: string,
 *     judgement: string,
 *     reason?: string,
 *     rejection?: RejectionWritten,
 *     changedLine: bool,
 *     carriedPruned?: true,
 *     coveredBy: list<int>,
 *     judgedBy?: list<int>,
 *     killedBy: list<int>,
 *     hint: string,
 *     reproduce: string,
 *     explain: string,
 *     seconds?: float,
 *     limit?: float,
 *     cluster?: string,
 *     removable?: true,
 * }
 */
final readonly class MutantJson
{
    /**
     * One mutant, with its tests by their places in the table (ADR-0014, decision 9).
     *
     * @return MutantEntry
     */
    public static function of(JudgedMutant|JudgedKill $judged, KillMatrix $matrix, TestTable $table): array
    {
        $mutant = $judged->mutant();
        $end = $mutant->location()->end();
        $covering = $matrix->coveredBy($judged);
        $judgedBy = $table->placesJudging($judged->tests(), $covering);

        return [
            'id' => $mutant->id()->value(),
            'file' => $mutant->location()->file()->value(),
            'line' => $mutant->location()->start()->number(),
            ...$end instanceof Line ? ['end' => $end->number()] : [],
            'mutator' => $mutant->mutator(),
            ...$mutant instanceof Mutant
                ? ['family' => $mutant->mutation()->family()->value, 'diff' => $mutant->mutation()->diff()]
                : [],
            'status' => $mutant->status()->value,
            'judgement' => $judged->judgement()->value,
            ...self::why($mutant),
            'changedLine' => $judged->isOnChangedLine(),
            ...$judged->isCarriedPruned() ? ['carriedPruned' => true] : [],
            'coveredBy' => $table->placesOf($covering),
            ...$judgedBy === [] ? [] : ['judgedBy' => $judgedBy],
            'killedBy' => $table->placesOf($judged->mutant()->killers()),
            'hint' => $judged->hint()->text(),
            'reproduce' => $judged->reproduce(),
            'explain' => $judged->explain(),
            ...self::optional($judged),
        ];
    }

    /**
     * What a mutant's entry holds only where it applies: how long it ran,
     * the limit it ran under, its cluster, and whether its callee may be
     * deleted.
     *
     * @return array{seconds?: float, limit?: float, cluster?: string, removable?: true}
     */
    private static function optional(JudgedMutant|JudgedKill $judged): array
    {
        $duration = $judged->mutant()->duration();
        $limit = $judged->mutant()->limit();
        $cluster = $judged instanceof JudgedMutant ? $judged->cluster() : Unclustered::mutant();
        $finding = $judged instanceof JudgedMutant ? $judged->finding() : NoFinding::survivor();

        return [
            ...$duration instanceof Seconds ? ['seconds' => $duration->seconds()] : [],
            ...$limit instanceof Seconds ? ['limit' => $limit->seconds()] : [],
            ...$cluster instanceof Membership ? ['cluster' => $cluster->id()->value()] : [],
            ...$finding instanceof Removable ? ['removable' => true] : [],
        ];
    }

    /**
     * Why the mutant stands as it does, where its record says: the reason
     * its runner gave, and the rejection of the analyser that killed it.
     *
     * @return array{reason?: string, rejection?: RejectionWritten}
     */
    private static function why(Mutant|ProvedKill $mutant): array
    {
        $reason = $mutant->reason();

        return match (true) {
            $reason instanceof Reason => ['reason' => $reason->text()],
            $reason instanceof Rejection => [MutantRecord::REJECTION => MutantRecord::rejection($reason)],
            default => [],
        };
    }
}
