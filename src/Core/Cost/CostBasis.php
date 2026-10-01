<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Cost;

/** What a unit's expected cost rests on (ADR-0006, decision 4). */
enum CostBasis: string
{
    /** What a shard last measured the unit to take. */
    case Learned = 'learned';

    /** What the plan's own coverage run says the unit's mutants take. */
    case Measured = 'measured';

    /** Lines of code times `costs.secondsPerLine`, where nothing was measured. */
    case Guessed = 'guessed';
}
