<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/**
 * Which floor a survivor fails for certain, so the run it is in cannot pass
 * (ADR-0008, decision 6), as a shard's result spells it.
 */
enum DoomedBy: string
{
    /** Its tree's floor, the higher of the declared one and the baseline's, is 100. */
    case Tree = 'tree';

    /** It is on a line the change added or modified, and the new-code floor that line is held to is 100. */
    case NewCode = 'newCode';
}
