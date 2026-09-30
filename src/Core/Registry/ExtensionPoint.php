<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Registry;

/**
 * What an extension registers under a name (ADR-0001): an adapter for each
 * port a config chooses by name, and a preset. Each is written as a message
 * names it.
 */
enum ExtensionPoint: string
{
    case Runner = 'runner';
    case TreeSource = 'tree source';
    case CostModel = 'cost model';
    case ProofStore = 'proof store';
    case CiPlan = 'CI plan';
    case Reporter = 'reporter';
    case ChangeSource = 'change source';
    case Repository = 'repository';
    case ConfigLoader = 'config loader';
    case StaticChecker = 'static checker';
    case Preset = 'preset';
}
