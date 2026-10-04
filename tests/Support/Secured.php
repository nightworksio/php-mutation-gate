<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Tests\Support;

use NightWorksIO\MutationGate\Core\File\Line;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Mutant\Location;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutantId;
use NightWorksIO\MutationGate\Core\Mutant\MutantStatus;
use NightWorksIO\MutationGate\Core\Mutant\Mutation;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\Runner\Uncovered;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Time\Unmeasured;
use NightWorksIO\MutationGate\Core\Tree\Package;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutant;
use NightWorksIO\MutationGate\Core\Verdict\JudgedMutants;
use NightWorksIO\MutationGate\Core\Verdict\JudgedUnits;
use NightWorksIO\MutationGate\Core\Verdict\MutantJudgement;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;

use function sprintf;

/** Security mutants, the trees that hold them and the security sets they make up, as tests name them. */
final class Secured
{
    /** The mutator the tests take as security-tagged. */
    public const string MUTATOR = 'security/HashEqualsToTrue';

    /** One mutant of a mutator, judged so, at a line of a file. */
    public static function mutant(
        MutantJudgement $judgement,
        int $line = 1,
        string $mutator = self::MUTATOR,
        string $file = 'src/Auth.php',
    ): JudgedMutant {
        $path = Path::of($file);
        $native = sprintf('%s:%d', $file, $line);

        return JudgedMutant::of(Mutant::of(
            MutantId::hash($path, $mutator, $native, 0),
            $native,
            Location::of($path, Line::of($line), Line::of($line)),
            Mutation::of($mutator, MutatorFamily::Condition, ''),
            MutantStatus::Killed,
            Unmeasured::duration(),
        ), $judgement);
    }

    /** A tree at a path of a package, holding these mutants, with no floor anywhere. */
    public static function tree(string $path, Package $package, JudgedMutant ...$mutants): TreeVerdict
    {
        return TreeVerdict::judged(
            Tree::at(Path::of($path), Undeclared::floor(), $package),
            Unrecorded::floor(),
            JudgedUnits::none(),
            JudgedMutants::of(...$mutants),
            Uncovered::Count,
        );
    }

    /** A package's security set of these mutants, held to these floors. */
    public static function set(
        string $package,
        Floor|Undeclared $declared,
        Floor|Unrecorded $baseline,
        JudgedMutant ...$mutants,
    ): SecurityVerdict {
        return SecurityVerdict::judged(
            Package::at(Path::of($package)),
            $declared,
            $baseline,
            JudgedMutants::of(...$mutants),
            Uncovered::Count,
        );
    }
}
