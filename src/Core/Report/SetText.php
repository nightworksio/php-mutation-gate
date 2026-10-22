<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Report;

use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\Format\Fit;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Sample\Estimate;
use NightWorksIO\MutationGate\Core\Score\Exempt;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Score\Undeclared;
use NightWorksIO\MutationGate\Core\Score\Unrecorded;
use NightWorksIO\MutationGate\Core\Verdict\Judgement;
use NightWorksIO\MutationGate\Core\Verdict\NewCodeVerdict;
use NightWorksIO\MutationGate\Core\Verdict\SecurityVerdict;
use NightWorksIO\MutationGate\Core\Verdict\TreeVerdict;

use function sprintf;

/**
 * The sentence every report says of a tree or a new-code set: its score
 * against its floor, that it has nothing to mutate, or why it is exempt, and
 * for a tree, its change against the base. A sampled tree's score is an
 * estimate, said with its interval and how much of the tree was sampled
 * (ADR-0020, decision 30).
 */
final readonly class SetText
{
    /** What every report says of a sampled run beside the project's score. */
    public const string SAMPLED
        = 'The run was sampled: a sampled tree\'s score is an estimate, and the project\'s counts the mutants it ran.';

    private const string BELOW = '%s scores %s, below its floor of %s.';

    private const string MET = '%s scores %s against its floor of %s.';

    private const string UNHELD = '%s scores %s, and no floor holds it.';

    private const string NOTHING = '%s has nothing to mutate.';

    private const string EXEMPT = '%s is exempt: %s';

    private const string AGAINST = '%s That is %s against the base.';

    /** A sampled tree's estimate: its name, the score, then the interval, its confidence and the units behind it. */
    private const string ESTIMATED = '%s scores about %s (%d%%: %s–%s; %d of %d units sampled, %d exact)';

    /** A sampled tree's estimate and its interval, as a table prints its score. */
    private const string ABOUT = 'about %s (%s–%s)';

    private const string ESTIMATED_MET = '%s against its floor of %s.';

    private const string ESTIMATED_BELOW = '%s, below its floor of %s.';

    private const string ESTIMATED_UNHELD = '%s, and no floor holds it.';

    private const string UNDECIDED
        = '%s, and its floor of %s lies within it: a smaller sampling.margin, or a full run, decides it.';

    private const string NEW_CODE = 'New code in %s';

    private const string NEW_CODE_HERE = 'New code';

    private const string SECURITY = 'Security in %s';

    private const string SECURITY_HERE = 'Security';

    public static function tree(TreeVerdict $tree): string
    {
        $estimate = self::sampled($tree);

        if ($estimate instanceof Estimate) {
            return self::estimated($tree, $estimate);
        }

        $said = self::sentence($tree->tree()->path()->value(), $tree->floor(), $tree->score(), $tree->judgement());
        $score = $tree->score();
        $base = $tree->base();

        return $score instanceof Score && $base instanceof Score
            ? sprintf(self::AGAINST, $said, Percent::change($base, $score))
            : $said;
    }

    /** A tree's score as a table prints it: its percentage, or a sampled tree's estimate and interval. */
    public static function score(TreeVerdict $tree): string
    {
        $estimate = self::sampled($tree);

        return $estimate instanceof Estimate
            ? sprintf(
                self::ABOUT,
                Percent::of($estimate->score()),
                Percent::ofHundredths($estimate->lower()),
                Percent::ofHundredths($estimate->upper()),
            )
            : Percent::of($tree->score());
    }

    /** A tree's change against the base, as a table prints it; nothing where either has no score, or it was sampled. */
    public static function change(TreeVerdict $tree): string
    {
        $score = $tree->score();
        $base = $tree->base();

        return $score instanceof Score && $base instanceof Score && self::sampled($tree) instanceof NotGiven
            ? Percent::change($base, $score)
            : '';
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

        return $package->equals(Path::root())
            ? self::NEW_CODE_HERE
            : sprintf(self::NEW_CODE, Fit::plain($package->value()));
    }

    public static function security(SecurityVerdict $set): string
    {
        return self::sentence(self::securityName($set), $set->floor(), $set->score(), $set->judgement());
    }

    /** What a security set is called: `Security`, or `Security in <package>` in a monorepo's package. */
    public static function securityName(SecurityVerdict $set): string
    {
        $package = $set->package()->path();

        return $package->equals(Path::root())
            ? self::SECURITY_HERE
            : sprintf(self::SECURITY, Fit::plain($package->value()));
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

    /** A tree's estimate, where a sample ran some of it; not given where the run knew it exactly. */
    private static function sampled(TreeVerdict $tree): Estimate|NotGiven
    {
        $estimate = $tree->estimate();

        return $estimate instanceof Estimate && $estimate->isSampled() ? $estimate : NotGiven::value();
    }

    /** A sampled tree's estimate, its interval and how much of it was sampled, against its floor. */
    private static function estimated(TreeVerdict $tree, Estimate $estimate): string
    {
        $floor = $tree->floor();
        $name = Fit::plain($tree->tree()->path()->value());
        $said = sprintf(
            self::ESTIMATED,
            $name,
            Percent::of($estimate->score()),
            $estimate->sample()->confidence()->value,
            Percent::ofHundredths($estimate->lower()),
            Percent::ofHundredths($estimate->upper()),
            $estimate->sampledUnits(),
            $estimate->totalUnits(),
            $estimate->exactUnits(),
        );

        return match (true) {
            $tree->judgement() === Judgement::NothingToMutate => sprintf(self::NOTHING, $name),
            ! $floor instanceof Floor => sprintf(self::ESTIMATED_UNHELD, $said),
            $tree->judgement() === Judgement::Failed => sprintf(self::ESTIMATED_BELOW, $said, Percent::of($floor)),
            $tree->judgement() === Judgement::Undecided => sprintf(self::UNDECIDED, $said, Percent::of($floor)),
            default => sprintf(self::ESTIMATED_MET, $said, Percent::of($floor)),
        };
    }

    /**
     * A set's sentence by its name, and an exempt one's reason, which a config
     * or a manifest writes: each one plain line, with no control character
     * that could colour a terminal or break the line.
     */
    private static function sentence(
        string $named,
        Floor|Exempt|Undeclared $floor,
        Score|NothingToMutate $score,
        Judgement $judgement,
    ): string {
        $name = Fit::plain($named);

        return match (true) {
            $floor instanceof Exempt => sprintf(self::EXEMPT, $name, Fit::plain($floor->reason())),
            $judgement === Judgement::NothingToMutate => sprintf(self::NOTHING, $name),
            ! $floor instanceof Floor => sprintf(self::UNHELD, $name, Percent::of($score)),
            $judgement === Judgement::Failed => sprintf(self::BELOW, $name, Percent::of($score), Percent::of($floor)),
            default => sprintf(self::MET, $name, Percent::of($score), Percent::of($floor)),
        };
    }
}
