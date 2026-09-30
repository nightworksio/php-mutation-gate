<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Score;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;
use NightWorksIO\MutationGate\Core\Unit\Units;

/**
 * Each tree's score on the base, for its change against the base (ADR-0009,
 * decision 3; ADR-0015, decision 11): the tree judged over the newest result
 * of every one of its units in the default branch's ledger. A tree with a
 * unit that has no result there has no base score, so no change is shown for
 * it rather than a wrong one.
 */
final readonly class BaseScores
{
    /** @param array<string, Score|NothingToMutate> $scores each measured tree's score, by its path */
    private function __construct(private array $scores)
    {
    }

    /** The base score of each of the judge's trees whose every unit the default branch's proofs hold a result for. */
    public static function of(Judge $judge, Trees $trees, Units $units, Proofs $defaultBranch): self
    {
        $newest = $defaultBranch->newest();
        $results = [];
        $unmeasured = Paths::none();

        foreach ($units as $unit) {
            $tree = $trees->holding($unit->path());
            $proof = $newest->of($unit->path());
            if ($proof instanceof Proof) {
                $results[] = UnitResult::held($unit, Origin::Carried, $proof->reported(), $proof->kills());
            }

            if (! $proof instanceof Proof && $tree instanceof Tree) {
                $unmeasured = $unmeasured->with($tree->path());
            }
        }

        $scores = [];

        foreach ($judge->trees(UnitResults::of(...$results)) as $verdict) {
            $path = $verdict->tree()->path();

            if (! $unmeasured->has($path)) {
                $scores[$path->value()] = $verdict->score();
            }
        }

        return new self($scores);
    }

    /** No tree has a base score. */
    public static function none(): self
    {
        return new self([]);
    }

    /** The verdicts, each compared with its tree's base score where it has one. */
    public function compare(TreeVerdicts $verdicts): TreeVerdicts
    {
        $compared = TreeVerdicts::none();

        foreach ($verdicts as $verdict) {
            $path = $verdict->tree()->path()->value();
            $compared = $compared->with(
                array_key_exists($path, $this->scores) ? $verdict->comparedWith($this->scores[$path]) : $verdict,
            );
        }

        return $compared;
    }
}
