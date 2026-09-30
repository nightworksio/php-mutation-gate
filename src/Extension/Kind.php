<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Extension;

/** Each kind of thing an extension registers, as a message names it. */
enum Kind: string
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
    case Preset = 'preset';
}
