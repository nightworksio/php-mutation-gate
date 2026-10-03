<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Cluster\Cluster;
use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Matrix\KillMatrix;
use NightWorksIO\MutationGate\Core\Mutant\IdPrefix;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Proof\Records;
use NightWorksIO\MutationGate\Core\Reach\Reasons;
use NightWorksIO\MutationGate\Core\Report\Explanation;
use NightWorksIO\MutationGate\Core\Report\Explanations;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;

/** What `explain` explains in the report tests: a mutant of `src/Money.php`, and a cluster of `src/Cart.php`. */
final class Explained
{
    /** The id of the mutant of `src/Money.php`'s sixteenth line. */
    public static function id(): MutantId
    {
        return MutantId::hash(Path::of('src/Money.php'), 'GreaterThan', '', 16);
    }

    /** The mutant of `src/Money.php`'s sixteenth line, with this status and no diff. */
    public static function mutant(MutantStatus $status): Mutant
    {
        return Mutant::of(
            self::id(),
            '',
            Location::of(Path::of('src/Money.php'), Line::of(16), Line::of(16)),
            Mutation::of('GreaterThan', MutatorFamily::Boundary, ''),
            $status,
            Unmeasured::duration(),
        );
    }

    /** The first cluster of the clustered survivors, each member as the last run judged it, run nowhere. */
    public static function cluster(): Explanations
    {
        $explained = Explanations::ofMutant(Explanation::recorded(
            JudgedMutant::of(self::mutant(MutantStatus::Survived), MutantJudgement::Survived),
            CannotTell::because('No cluster.'),
            Records::none(IdPrefix::of(self::id())),
        ));

        foreach (Clustered::verdict()->trees()->clusters() as $cluster) {
            $explained = $explained->cluster() instanceof Cluster ? $explained : self::of($cluster);
        }

        return $explained;
    }

    private static function of(Cluster $cluster): Explanations
    {
        $members = [];

        foreach ($cluster->members() as $member) {
            $members[] = Explanation::judged(
                $member,
                KillMatrix::none(),
                CannotTell::because('Not run.'),
                Reasons::of(),
                Records::none(IdPrefix::of($member->mutant()->id())),
            );
        }

        return Explanations::ofCluster($cluster, ...$members);
    }
}
