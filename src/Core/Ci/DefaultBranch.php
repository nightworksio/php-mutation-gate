<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Ci;

use NightWorksIO\MutationGate\Core\Change\CannotTell;
use NightWorksIO\MutationGate\Core\Config\Absent;
use NightWorksIO\MutationGate\Core\Proof\Scope;

/**
 * The project's default branch (ADR-0006 decision 5): `ci.defaultBranch`
 * where the config sets it, then the first branch that is detected, such as
 * the CI's or the one git's `origin/HEAD` points at, and `main` where nothing
 * names one.
 */
final readonly class DefaultBranch
{
    /** The branch taken where nothing names one. */
    private const string FALLBACK = 'main';

    /** The branch the config names, else the first of these that is one, else `main`. */
    public static function of(string|Absent $configured, Scope|CannotTell ...$detected): Scope
    {
        $named = $configured instanceof Absent ? $configured : RunOn::branchNamed($configured);

        foreach ([$named, ...$detected] as $branch) {
            if ($branch instanceof Scope) {
                return $branch;
            }
        }

        return Scope::branch(self::FALLBACK);
    }
}
