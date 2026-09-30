<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;

use function sprintf;

/**
 * The sentence every report says of a tree or a new-code set: its score
 * against its floor, that it has nothing to mutate, or why it is exempt, and
 * for a tree, its change against the base.
 */
final readonly class SetText
{
    private const string BELOW = '%s scores %s, below its floor of %s.';

    private const string MET = '%s scores %s against its floor of %s.';

    private const string UNHELD = '%s scores %s, and no floor holds it.';

    private const string NOTHING = '%s has nothing to mutate.';

    private const string EXEMPT = '%s is exempt: %s';

    private const string AGAINST = '%s That is %s against the base.';

    private const string NEW_CODE = 'New code in %s';

    private const string NEW_CODE_HERE = 'New code';

    public static function tree(TreeVerdict $tree): string
    {
        $said = self::sentence($tree->tree()->path()->value(), $tree->floor(), $tree->score(), $tree->judgement());
        $score = $tree->score();
        $base = $tree->base();

        return $score instanceof Score && $base instanceof Score
            ? sprintf(self::AGAINST, $said, Percent::change($base, $score))
            : $said;
    }

    /** What the whole project scored, or that it has nothing to mutate. */
    public static function project(Score|NothingToMutate $score): string
    {
        return $score instanceof Score
            ? sprintf('The project scores %s.', Percent::of($score))
            : 'The project has nothing to mutate.';
    }

    public static function newCode(NewCodeVerdict $set): string
    {
        return self::sentence(self::newCodeName($set), $set->floor(), $set->score(), $set->judgement());
    }

    /** What a new-code set is called: `New code`, or `New code in <package>` in a monorepo's package. */
    public static function newCodeName(NewCodeVerdict $set): string
    {
        $package = $set->package()->path();

        return $package->equals(Path::root()) ? self::NEW_CODE_HERE : sprintf(self::NEW_CODE, $package->value());
    }

    /** A floor as a report prints it: its percentage, `exempt`, or `none`. */
    public static function floor(Floor|Exempt|Undeclared|Unrecorded $floor): string
    {
        return match (true) {
            $floor instanceof Floor => Percent::of($floor),
            $floor instanceof Exempt => 'exempt',
            default => 'none',
        };
    }

    private static function sentence(
        string $name,
        Floor|Exempt|Undeclared $floor,
        Score|NothingToMutate $score,
        Judgement $judgement,
    ): string {
        return match (true) {
            $floor instanceof Exempt => sprintf(self::EXEMPT, $name, $floor->reason()),
            $judgement === Judgement::NothingToMutate => sprintf(self::NOTHING, $name),
            ! $floor instanceof Floor => sprintf(self::UNHELD, $name, Percent::of($score)),
            $judgement === Judgement::Failed => sprintf(self::BELOW, $name, Percent::of($score), Percent::of($floor)),
            default => sprintf(self::MET, $name, Percent::of($score), Percent::of($floor)),
        };
    }
}
