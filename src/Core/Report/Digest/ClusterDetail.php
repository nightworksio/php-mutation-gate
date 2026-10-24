<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report\Digest;

use function count;
use function in_array;

use NightWorksIO\MutationGate\Core\CannotJudge;
use NightWorksIO\MutationGate\Core\Cluster\ClusterId;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;

use function sprintf;

/**
 * The digest's detail of one cluster of survivors that share a cause: its
 * kind, each member as the overview lists a survivor, and the command that
 * writes one test for them all.
 */
final readonly class ClusterDetail
{
    private const string CLUSTER = 'cluster %s kind=%s members=%d';

    private const string STUB = 'stub mutation-gate stub %s';

    /**
     * @param list<DigestMutant> $mutants every mutant of the report
     *
     * @throws NotInShape
     */
    public static function of(Node $report, ClusterId $sought, array $mutants): string|CannotJudge
    {
        foreach ($report->field('clusters')->items() as $cluster) {
            if ($cluster->field('id')->text() === $sought->value()) {
                return self::said($sought, $cluster, $mutants);
            }
        }

        return DigestDetail::nothing($sought->value());
    }

    /**
     * @param list<DigestMutant> $mutants
     *
     * @throws NotInShape
     */
    private static function said(ClusterId $id, Node $cluster, array $mutants): string
    {
        $members = [];

        foreach ($cluster->field('members')->items() as $member) {
            $members[] = $member->text();
        }

        $listed = [];

        foreach ($mutants as $mutant) {
            if (in_array($mutant->id(), $members, strict: true)) {
                $listed[] = $mutant;
            }
        }

        return Digest::lines([
            sprintf(self::CLUSTER, $id->value(), $cluster->field('kind')->text(), count($members)),
            ...Digest::grouped($listed),
            sprintf(self::STUB, $id->value()),
        ]);
    }
}
