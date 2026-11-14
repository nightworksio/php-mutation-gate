<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Plan;

use function array_any;
use function array_unique;

use DateTimeImmutable;
use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Change\Change;
use NightWorksIO\MutationGate\Core\Change\Changes;
use NightWorksIO\MutationGate\Core\Change\Revision;
use NightWorksIO\MutationGate\Core\Config\Ignored;
use NightWorksIO\MutationGate\Core\Config\Listed;
use NightWorksIO\MutationGate\Core\File\Globs;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Proof\GateRelease;
use NightWorksIO\MutationGate\Core\Proof\Passed;
use NightWorksIO\MutationGate\Core\Proof\Proof;
use NightWorksIO\MutationGate\Core\Proof\Proofs;
use NightWorksIO\MutationGate\Core\Reach\Reason;
use NightWorksIO\MutationGate\Core\Time\Instant;
use NightWorksIO\MutationGate\Core\Tree\Tree;
use NightWorksIO\MutationGate\Core\Tree\Trees;

use function sort;
use function sprintf;

/**
 * Whether the verdict of the commit a plan that runs no unit is read since
 * stands for it (ADR-0062): the default branch's ledger records that commit
 * as passed, under the run's own check, at an instant and by the release of
 * the gate that runs now, and holds no result a later run left; what changed
 * from it is what changed since its merge base with HEAD; no path that change touched, as it is and as it was, is in a
 * tree, is the baseline or holds the config, whose floors and ignores no
 * reach rule counts; and no ignore that applied when it passed has expired
 * since. Anything else, and the verdict judges the carried results.
 *
 * @internal
 */
final readonly class ReachingNothing
{
    private const string NO_TIME = 'The default branch\'s ledger does not say when %s passed.';

    private const string OTHER_COMMIT = 'The change is read since %s, and the commit whose verdict passed is %s.';

    private const string LATER = 'A run after %s passed left results in the default branch\'s ledger.';

    private const string NOT_AN_ANCESTOR
        = 'What changed from %s itself is not what changed since its merge base with HEAD.';

    private const string IN_A_TREE = '%s is in the tree %s.';

    private function __construct(
        private Trees $trees,
        private Path $baseline,
        private Globs $config,
        private string $check,
        private GateRelease $gate,
    ) {
    }

    /**
     * Judged against these trees, with the baseline at this path and the
     * config in these files, for a run reporting under this check by this
     * release of the gate.
     */
    public static function within(
        Trees $trees,
        Path $baseline,
        Globs $config,
        string $check,
        GateRelease $gate,
    ): self {
        return new self($trees, $baseline, $config, $check, $gate);
    }

    /**
     * The verdict that stands for a plan that runs no unit, read since this
     * revision, judged now with these ignores; or why it does not.
     *
     * @param Proofs          $proofs     the default branch's ledger's results
     * @param Changes|CannotTell $fromPassed what changed from the commit that passed itself
     * @param Changes|CannotTell $sinceBase  what changed since the merge base of the revision and HEAD
     * @param Listed<Ignored> $ignores
     */
    public function stands(
        Passed|CannotTell $passed,
        Proofs $proofs,
        Revision $since,
        Changes|CannotTell $fromPassed,
        Changes|CannotTell $sinceBase,
        Listed $ignores,
        DateTimeImmutable $now,
    ): Unchanged|Reason {
        if ($passed instanceof CannotTell) {
            return Reason::that($passed->why());
        }

        $at = $passed->at();
        $standing = $at instanceof Instant
            ? $this->standing($passed, $at, $proofs, $since, $fromPassed, $sinceBase)
            : Reason::that(sprintf(self::NO_TIME, $passed->commit()->name()));

        return $standing instanceof Unchanged ? $this->unexpired($standing, $ignores, $now) : $standing;
    }

    /**
     * The verdict that stands, where no ignore that applied when it passed has expired by now; or why it does not.
     *
     * @param Listed<Ignored> $ignores
     */
    private function unexpired(Unchanged $standing, Listed $ignores, DateTimeImmutable $now): Unchanged|Reason
    {
        $expired = $standing->expiredBy($ignores, $now);

        return $expired instanceof Ignored
            ? Reason::that(sprintf(Unchanging::EXPIRED, $expired->named(), $standing->base()->commit()->name()))
            : $standing;
    }

    /** The verdict of a commit that passed at an instant, standing for the change since; or why it does not. */
    private function standing(
        Passed $passed,
        Instant $at,
        Proofs $proofs,
        Revision $since,
        Changes|CannotTell $fromPassed,
        Changes|CannotTell $sinceBase,
    ): Unchanged|Reason {
        $named = $passed->commit()->name();
        $gate = Unchanging::otherGate($passed, $this->gate);
        $touched = match (true) {
            $passed->check() !== $this->check
                => Reason::that(sprintf(Unchanging::OTHER_CHECK, $named, $passed->check(), $this->check)),
            $gate instanceof Reason => $gate,
            $since->name() !== $named => Reason::that(sprintf(self::OTHER_COMMIT, $since->name(), $named)),
            $this->laterThan($proofs, $at) => Reason::that(sprintf(self::LATER, $named)),
            default => $this->touched($named, $fromPassed, $sinceBase),
        };

        return $touched instanceof Reason
            ? $touched
            : Unchanged::standing(StandsBecause::NothingReached, $passed, $at);
    }

    /** Whether any of these results was left by a run after this instant. */
    private function laterThan(Proofs $proofs, Instant $at): bool
    {
        return array_any([...$proofs], static fn(Proof $proof): bool => $proof->run()->at()->isAfter($at));
    }

    /** Why the change from the commit that passed keeps its verdict from standing; none where nothing does. */
    private function touched(
        string $named,
        Changes|CannotTell $fromPassed,
        Changes|CannotTell $sinceBase,
    ): Reason|NotGiven {
        return match (true) {
            $fromPassed instanceof CannotTell => Reason::that($fromPassed->why()),
            $sinceBase instanceof CannotTell => Reason::that($sinceBase->why()),
            $this->pathsOf($fromPassed) !== $this->pathsOf($sinceBase)
                => Reason::that(sprintf(self::NOT_AN_ANCESTOR, $named)),
            default => $this->judged($fromPassed),
        };
    }

    /** Why the first path these changes touch, as it is or as it was, counts in the verdict; none where none does. */
    private function judged(Changes $changes): Reason|NotGiven
    {
        foreach ($changes as $change) {
            $why = $this->counted($change->previousPath());
            $why = $why instanceof Reason ? $why : $this->counted($change->path());

            if ($why instanceof Reason) {
                return $why;
            }
        }

        return NotGiven::value();
    }

    /** Why a path counts in the verdict: it is in a tree, the baseline or the config; none where it is none. */
    private function counted(Path $path): Reason|NotGiven
    {
        $tree = $this->trees->holding($path);

        return match (true) {
            $tree instanceof Tree => Reason::that(sprintf(self::IN_A_TREE, $path->value(), $tree->path()->value())),
            $path->equals($this->baseline) => Reason::that(sprintf(Unchanging::BASELINE, $path->value())),
            $this->config->matches($path) => Reason::that(sprintf(Unchanging::DECIDES, $path->value())),
            default => NotGiven::value(),
        };
    }

    /** @return list<string> every path these changes touch, as it is and as it was, once and in order */
    private function pathsOf(Changes $changes): array
    {
        $paths = [];

        foreach ($changes as $change) {
            $paths = [...$paths, ...$this->bothOf($change)];
        }

        $paths = array_unique($paths);
        sort($paths);

        return $paths;
    }

    /** @return list<string> */
    private function bothOf(Change $change): array
    {
        return [$change->previousPath()->value(), $change->path()->value()];
    }
}
