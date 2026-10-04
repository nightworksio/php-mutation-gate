<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateSymfony;

use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGateSymfony\Mutators\CsrfValidToTrue;
use NightWorksIO\MutationGateSymfony\Mutators\FormValidToTrue;
use NightWorksIO\MutationGateSymfony\Mutators\IsGrantedToTrue;
use NightWorksIO\MutationGateSymfony\Mutators\PasswordValidToTrue;
use NightWorksIO\MutationGateSymfony\Mutators\RemoveAccessDeniedThrow;
use NightWorksIO\MutationGateSymfony\Mutators\RemoveDenyAccess;
use NightWorksIO\MutationGateSymfony\Mutators\RemoveFlush;
use NightWorksIO\MutationGateSymfony\Mutators\RemoveIsGrantedAttribute;
use NightWorksIO\MutationGateSymfony\Mutators\RemoveMessageDispatch;

/**
 * Registers the `symfony` set: mutants of a Symfony app's access checks, CSRF
 * and password checks, forms, flushes and message dispatches, matched on
 * syntax and resolved names, so the set needs no Symfony installed
 * (ADR-0021).
 */
final readonly class SymfonyExtension implements Extension
{
    public function extend(Extensions $extensions): Extensions
    {
        return $extensions->withMutators(
            SymfonySet::name(),
            MutatorSet::of(
                RemoveDenyAccess::class,
                IsGrantedToTrue::class,
                CsrfValidToTrue::class,
                RemoveIsGrantedAttribute::class,
                RemoveAccessDeniedThrow::class,
                PasswordValidToTrue::class,
                FormValidToTrue::class,
                RemoveFlush::class,
                RemoveMessageDispatch::class,
            ),
        );
    }
}
