<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Mutant;

/** The kind of change a mutator makes, which decides what a survivor's hint says. */
enum MutatorFamily: string
{
    case Boundary = 'boundary';
    case Condition = 'condition';
    case Logical = 'logical';
    case Arithmetic = 'arithmetic';
    case ReturnValue = 'return-value';
    case RemovedCall = 'removed-call';
    case Literal = 'literal';
    case Collection = 'collection';
    case Exception = 'exception';
    case Unwrap = 'unwrap';
    case Visibility = 'visibility';
    /** A mutator marked as belonging to no family; its survivors show the diff and the covering tests. */
    case None = 'none';
}
