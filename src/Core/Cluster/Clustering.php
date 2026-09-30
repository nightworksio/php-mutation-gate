<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cluster;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\ByPath;
use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\MutantIds;
use NightWorksIO\MutationGate\Core\Php\Functions;
use NightWorksIO\MutationGate\Core\Report\Columns;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;

use function strval;

/**
 * Which survivors share one cause, file by file, by the two rules of a
 * cluster: first their changes overlapping within one statement, then, among
 * the rest, one family in one function judged by the same tests. A cluster
 * holds two survivors at least, and a survivor is in one at most. A file
 * that cannot be read clusters nothing (ADR-0022, decision 15).
 */
final readonly class Clustering
{
    /**
     * @param  list<JudgedMutant>        $survivors
     * @param  ByPath<Contents>          $sources   each file's source, by its path
     * @return array<string, Membership>            by mutant id
     */
    public static function of(array $survivors, ByPath $sources): array
    {
        $byFile = [];

        foreach ($survivors as $judged) {
            $byFile[$judged->mutant()->location()->file()->value()][] = $judged;
        }

        $memberships = [];

        foreach ($byFile as $path => $mutants) {
            $memberships += self::inFile($mutants, $sources->at(Path::of(strval($path)), Contents::of('')));
        }

        return $memberships;
    }

    /**
     * @param  list<JudgedMutant>        $mutants
     * @return array<string, Membership>
     */
    private static function inFile(array $mutants, Contents $source): array
    {
        $expressions = self::memberships(Expressions::among($mutants, Columns::in($source)), ClusterKind::Expression);
        $rest = [];

        foreach ($mutants as $judged) {
            if (! array_key_exists($judged->mutant()->id()->value(), $expressions)) {
                $rest[] = $judged;
            }
        }

        return $expressions + self::memberships(Gaps::among($rest, Functions::in($source)), ClusterKind::Gap);
    }

    /**
     * @param  list<list<JudgedMutant>>  $groups
     * @return array<string, Membership>
     */
    private static function memberships(array $groups, ClusterKind $kind): array
    {
        $memberships = [];

        foreach ($groups as $group) {
            $ids = MutantIds::none();

            foreach ($group as $judged) {
                $ids = $ids->and(MutantIds::of($judged->mutant()->id()));
            }

            $membership = Membership::of(ClusterId::of($ids), $kind);

            foreach ($group as $judged) {
                $memberships[$judged->mutant()->id()->value()] = $membership;
            }
        }

        return $memberships;
    }
}
