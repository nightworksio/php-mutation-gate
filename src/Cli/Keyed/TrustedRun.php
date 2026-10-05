<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Cli\Keyed;

use NightWorksIO\MutationGate\Adapter\GitHub\TrustedEvent;
use NightWorksIO\MutationGate\Core\Ci\Variables;
use NightWorksIO\MutationGate\Core\NotWritten;
use NightWorksIO\MutationGate\Core\Proof\Scope;

/**
 * The one scope `deliver` writes a ledger to, decided from its own run before it reads a delivery (ADR-0007
 * decision 5): the default branch's, where GitHub Actions says the run is a push, a schedule or a manual run of that
 * branch, by its event and its ref. Any other run, a run whose default branch nothing names, and any other CI,
 * writes none.
 */
final readonly class TrustedRun
{
    private const string NOT_GITHUB = 'deliver writes a ledger only in GitHub Actions, so this run writes none.';

    private const string UNTRUSTED
        = 'This run is no push, schedule or manual run of the default branch, so deliver writes no ledger.';

    public static function scopeIn(Variables $environment): Scope|NotWritten
    {
        if (! $environment->onGitHubActions()) {
            return NotWritten::because(self::NOT_GITHUB);
        }

        $default = DefaultBranch::scopeIn($environment);
        $ref = Scope::parse($environment->valueOf('GITHUB_REF'));

        return TrustedEvent::names($environment->valueOf('GITHUB_EVENT_NAME'))
            && $default instanceof Scope
            && $ref instanceof Scope
            && $ref->equals($default)
            ? $default
            : NotWritten::because(self::UNTRUSTED);
    }
}
