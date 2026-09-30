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
    /** A mutator the runner's table of families does not know; its survivors show what `None`'s do. */
    case Unknown = 'unknown';
    /** A mutant a proof kept too briefly to record its family, which only a killed mutant is. */
    case Unrecorded = 'unrecorded';

    /** Whether it names a kind of change a set of mutators shares, as none, unknown and unrecorded do not. */
    public function isKind(): bool
    {
        return match ($this) {
            self::None, self::Unknown, self::Unrecorded => false,
            self::Boundary,
            self::Condition,
            self::Logical,
            self::Arithmetic,
            self::ReturnValue,
            self::RemovedCall,
            self::Literal,
            self::Collection,
            self::Exception,
            self::Unwrap,
            self::Visibility => true,
        };
    }
}
