<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

use NightWorksIO\MutationGate\Core\Baseline\BaselineFile;
use NightWorksIO\MutationGate\Core\File\Path;
use NightWorksIO\MutationGate\Core\File\Paths;
use NightWorksIO\MutationGate\Core\Score\Floor;
use NightWorksIO\MutationGate\Core\Score\NothingToMutate;
use NightWorksIO\MutationGate\Core\Score\Undeclared;

use function sprintf;

/**
 * What keeps floors rising: the trees and security sets a score holds to no
 * floor at all, and, where a raise must be committed with the change that
 * earned it, a failure for each floor the score raised.
 */
final readonly class Ratchet
{
    private const string RAISE = <<<'SAID'
        %1$s scored %2$s, above the floor of %3$s it was held to. Commit the raised floor with this change:
        run mutation-gate baseline --write and commit %4$s.
        SAID;

    private const string UNFLOORED = <<<'SAID'
        %s has no floor: no floor is declared for it, and the baseline holds none.
        A tree is never held to no floor. Run mutation-gate baseline --write and commit %s.
        SAID;

    private const string SECURITY_RAISE = <<<'SAID'
        The security set of %1$s scored %2$s, above the floor of %3$s it was held to.
        Commit the raised floor with this change: run mutation-gate baseline --write and commit %4$s.
        SAID;

    private const string SECURITY_UNFLOORED = <<<'SAID'
        The security set of %s has no floor: neither security.floor nor the package's securityFloor
        declares one, and the baseline holds none.
        A security set is never held to no floor. Run mutation-gate baseline --write and commit %s.
        SAID;

    /** The trees with a score and no floor anywhere, neither declared nor in the baseline. */
    public static function unfloored(TreeVerdicts $verdicts): Paths
    {
        $unfloored = Paths::none();

        foreach ($verdicts as $verdict) {
            $held = $verdict->floor() instanceof Undeclared && ! $verdict->score() instanceof NothingToMutate;
            $unfloored = $held ? $unfloored->with($verdict->tree()->path()) : $unfloored;
        }

        return $unfloored;
    }

    /** Why a run stops on trees held to no floor, naming each. */
    public static function unflooredBecause(Paths $trees, Path $baseline): Failures
    {
        $failures = Failures::none();

        foreach ($trees as $tree) {
            $failures = $failures->with(Failure::that(sprintf(self::UNFLOORED, $tree->value(), $baseline->value())));
        }

        return $failures;
    }

    /** A failure for every floor a score raised, until the raised floor is committed. */
    public static function required(TreeVerdicts $verdicts, Path $baseline): Failures
    {
        $failures = Failures::none();

        foreach ($verdicts as $verdict) {
            $raised = $verdict->raised();
            $floor = $verdict->floor();
            $failures = $raised instanceof Floor && $floor instanceof Floor
                ? $failures->with(Failure::that(sprintf(
                    self::RAISE,
                    $verdict->tree()->path()->value(),
                    BaselineFile::number($raised),
                    BaselineFile::number($floor),
                    $baseline->value(),
                )))
                : $failures;
        }

        return $failures;
    }

    /** The packages whose security set has a score and no floor anywhere, neither declared nor in the baseline. */
    public static function securityUnfloored(SecurityVerdicts $verdicts): Paths
    {
        $unfloored = Paths::none();

        foreach ($verdicts as $verdict) {
            $held = $verdict->floor() instanceof Undeclared && ! $verdict->score() instanceof NothingToMutate;
            $unfloored = $held ? $unfloored->with($verdict->package()->path()) : $unfloored;
        }

        return $unfloored;
    }

    /** Why a run stops on security sets held to no floor, naming each set's package. */
    public static function securityUnflooredBecause(Paths $packages, Path $baseline): Failures
    {
        $failures = Failures::none();

        foreach ($packages as $package) {
            $failures = $failures->with(
                Failure::that(sprintf(self::SECURITY_UNFLOORED, $package->value(), $baseline->value())),
            );
        }

        return $failures;
    }

    /** A failure for every security floor a score raised, until the raised floor is committed. */
    public static function securityRequired(SecurityVerdicts $verdicts, Path $baseline): Failures
    {
        $failures = Failures::none();

        foreach ($verdicts as $verdict) {
            $raised = $verdict->raised();
            $floor = $verdict->floor();
            $failures = $raised instanceof Floor && $floor instanceof Floor
                ? $failures->with(Failure::that(sprintf(
                    self::SECURITY_RAISE,
                    $verdict->package()->path()->value(),
                    BaselineFile::number($raised),
                    BaselineFile::number($floor),
                    $baseline->value(),
                )))
                : $failures;
        }

        return $failures;
    }
}
