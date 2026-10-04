<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel;

use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGateLaravel\Mutators\AuthCheckToTrue;
use NightWorksIO\MutationGateLaravel\Mutators\FirstOrFailToFirst;
use NightWorksIO\MutationGateLaravel\Mutators\GateAllowsToTrue;
use NightWorksIO\MutationGateLaravel\Mutators\HashCheckToTrue;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveAbort;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveAuthAbort;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveAuthorize;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveDispatch;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveGuardedEntry;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveMiddleware;
use NightWorksIO\MutationGateLaravel\Mutators\RemoveValidationRule;
use NightWorksIO\MutationGateLaravel\Mutators\UnwrapCacheRemember;
use NightWorksIO\MutationGateLaravel\Mutators\UnwrapEscape;
use NightWorksIO\MutationGateLaravel\Mutators\UnwrapTransaction;

/**
 * Registers the `laravel` set: mutants of a Laravel app's gates, guards,
 * escaping, validation, transactions, caches and dispatches, matched on
 * syntax and resolved names, so the set needs no Laravel installed
 * (ADR-0021).
 */
final readonly class LaravelExtension implements Extension
{
    public function extend(Extensions $extensions): Extensions
    {
        return $extensions->withMutators(
            LaravelSet::name(),
            MutatorSet::of(
                GateAllowsToTrue::class,
                RemoveAuthorize::class,
                RemoveAbort::class,
                RemoveAuthAbort::class,
                AuthCheckToTrue::class,
                HashCheckToTrue::class,
                UnwrapEscape::class,
                RemoveValidationRule::class,
                FirstOrFailToFirst::class,
                UnwrapTransaction::class,
                UnwrapCacheRemember::class,
                RemoveDispatch::class,
                RemoveMiddleware::class,
                RemoveGuardedEntry::class,
            ),
        );
    }
}
