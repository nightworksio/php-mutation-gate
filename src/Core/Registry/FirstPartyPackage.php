<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Registry;

use NightWorksIO\MutationGate\Core\ThisPackage;

/**
 * The Composer packages this repository ships: the gate, and each plugin under
 * `plugins/`. Their extensions are first party, so `--no-extensions`, which
 * loads no third-party code, still loads them (ADR-0023 decision 8).
 */
enum FirstPartyPackage: string
{
    case Gate = ThisPackage::COMPOSER;
    case DefaultSet = 'nightworksio/mutation-gate-default';
    case LaravelSet = 'nightworksio/mutation-gate-laravel';
    case SymfonySet = 'nightworksio/mutation-gate-symfony';
    case SecuritySet = 'nightworksio/mutation-gate-security';
}
