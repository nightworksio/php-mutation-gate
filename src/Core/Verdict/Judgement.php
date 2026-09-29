<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Verdict;

/** Whether a set of mutants met its floor. A set with nothing to mutate passes. */
enum Judgement: string
{
    case Passed = 'passed';
    case Failed = 'failed';
}
