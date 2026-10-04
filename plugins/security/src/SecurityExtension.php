<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSecurity;

use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGateSecurity\Mutators\FilterVarToInput;
use NightWorksIO\MutationGateSecurity\Mutators\HashEqualsToIdentical;
use NightWorksIO\MutationGateSecurity\Mutators\HashEqualsToTrue;
use NightWorksIO\MutationGateSecurity\Mutators\PasswordVerifyToTrue;
use NightWorksIO\MutationGateSecurity\Mutators\RemoveSessionRegenerate;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapHtmlentities;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapHtmlspecialchars;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapShellEscape;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapStripTags;
use NightWorksIO\MutationGateSecurity\Mutators\UnwrapUrlEncode;

/**
 * Registers the `security` set: mutants of the defences PHP itself offers,
 * whatever the framework (ADR-0021). Every one of them is tagged `security`.
 */
final readonly class SecurityExtension implements Extension
{
    public function extend(Extensions $extensions): Extensions
    {
        return $extensions->withMutators(
            SecuritySet::name(),
            MutatorSet::of(
                HashEqualsToTrue::class,
                HashEqualsToIdentical::class,
                PasswordVerifyToTrue::class,
                UnwrapHtmlspecialchars::class,
                UnwrapHtmlentities::class,
                UnwrapStripTags::class,
                UnwrapShellEscape::class,
                UnwrapUrlEncode::class,
                FilterVarToInput::class,
                RemoveSessionRegenerate::class,
            ),
        );
    }
}
