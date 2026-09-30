<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;

use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use Pest\Mutate\Mutators\Arithmetic\BitwiseAndToBitwiseOr;
use Pest\Mutate\Mutators\Arithmetic\BitwiseOrToBitwiseAnd;
use Pest\Mutate\Mutators\Arithmetic\BitwiseXorToBitwiseAnd;
use Pest\Mutate\Mutators\Arithmetic\DivisionToMultiplication;
use Pest\Mutate\Mutators\Arithmetic\MinusToPlus;
use Pest\Mutate\Mutators\Arithmetic\ModulusToMultiplication;
use Pest\Mutate\Mutators\Arithmetic\MultiplicationToDivision;
use Pest\Mutate\Mutators\Arithmetic\PlusToMinus;
use Pest\Mutate\Mutators\Arithmetic\PostDecrementToPostIncrement;
use Pest\Mutate\Mutators\Arithmetic\PostIncrementToPostDecrement;
use Pest\Mutate\Mutators\Arithmetic\PowerToMultiplication;
use Pest\Mutate\Mutators\Arithmetic\PreDecrementToPreIncrement;
use Pest\Mutate\Mutators\Arithmetic\PreIncrementToPreDecrement;
use Pest\Mutate\Mutators\Arithmetic\ShiftLeftToShiftRight;
use Pest\Mutate\Mutators\Arithmetic\ShiftRightToShiftLeft;
use Pest\Mutate\Mutators\Array\ArrayKeyFirstToArrayKeyLast;
use Pest\Mutate\Mutators\Array\ArrayKeyLastToArrayKeyFirst;
use Pest\Mutate\Mutators\Array\ArrayPopToArrayShift;
use Pest\Mutate\Mutators\Array\ArrayShiftToArrayPop;
use Pest\Mutate\Mutators\Array\UnwrapArrayChangeKeyCase;
use Pest\Mutate\Mutators\Array\UnwrapArrayChunk;
use Pest\Mutate\Mutators\Array\UnwrapArrayColumn;
use Pest\Mutate\Mutators\Array\UnwrapArrayCombine;
use Pest\Mutate\Mutators\Array\UnwrapArrayCountValues;
use Pest\Mutate\Mutators\Array\UnwrapArrayDiff;
use Pest\Mutate\Mutators\Array\UnwrapArrayDiffAssoc;
use Pest\Mutate\Mutators\Array\UnwrapArrayDiffKey;
use Pest\Mutate\Mutators\Array\UnwrapArrayDiffUassoc;
use Pest\Mutate\Mutators\Array\UnwrapArrayDiffUkey;
use Pest\Mutate\Mutators\Array\UnwrapArrayFilter;
use Pest\Mutate\Mutators\Array\UnwrapArrayFlip;
use Pest\Mutate\Mutators\Array\UnwrapArrayIntersect;
use Pest\Mutate\Mutators\Array\UnwrapArrayIntersectAssoc;
use Pest\Mutate\Mutators\Array\UnwrapArrayIntersectKey;
use Pest\Mutate\Mutators\Array\UnwrapArrayIntersectUassoc;
use Pest\Mutate\Mutators\Array\UnwrapArrayIntersectUkey;
use Pest\Mutate\Mutators\Array\UnwrapArrayKeys;
use Pest\Mutate\Mutators\Array\UnwrapArrayMap;
use Pest\Mutate\Mutators\Array\UnwrapArrayMerge;
use Pest\Mutate\Mutators\Array\UnwrapArrayMergeRecursive;
use Pest\Mutate\Mutators\Array\UnwrapArrayPad;
use Pest\Mutate\Mutators\Array\UnwrapArrayReduce;
use Pest\Mutate\Mutators\Array\UnwrapArrayReplace;
use Pest\Mutate\Mutators\Array\UnwrapArrayReplaceRecursive;
use Pest\Mutate\Mutators\Array\UnwrapArrayReverse;
use Pest\Mutate\Mutators\Array\UnwrapArraySlice;
use Pest\Mutate\Mutators\Array\UnwrapArraySplice;
use Pest\Mutate\Mutators\Array\UnwrapArrayUdiff;
use Pest\Mutate\Mutators\Array\UnwrapArrayUdiffAssoc;
use Pest\Mutate\Mutators\Array\UnwrapArrayUdiffUassoc;
use Pest\Mutate\Mutators\Array\UnwrapArrayUintersect;
use Pest\Mutate\Mutators\Array\UnwrapArrayUintersectAssoc;
use Pest\Mutate\Mutators\Array\UnwrapArrayUintersectUassoc;
use Pest\Mutate\Mutators\Array\UnwrapArrayUnique;
use Pest\Mutate\Mutators\Array\UnwrapArrayValues;
use Pest\Mutate\Mutators\Assignment\CoalesceEqualToEqual;
use Pest\Mutate\Mutators\Assignment\ConcatEqualToEqual;
use Pest\Mutate\Mutators\Assignment\DivideEqualToMultiplyEqual;
use Pest\Mutate\Mutators\Assignment\MinusEqualToPlusEqual;
use Pest\Mutate\Mutators\Assignment\ModulusEqualToMultiplyEqual;
use Pest\Mutate\Mutators\Assignment\MultiplyEqualToDivideEqual;
use Pest\Mutate\Mutators\Assignment\PlusEqualToMinusEqual;
use Pest\Mutate\Mutators\Assignment\PowerEqualToMultiplyEqual;
use Pest\Mutate\Mutators\Casting\RemoveArrayCast;
use Pest\Mutate\Mutators\Casting\RemoveBooleanCast;
use Pest\Mutate\Mutators\Casting\RemoveDoubleCast;
use Pest\Mutate\Mutators\Casting\RemoveIntegerCast;
use Pest\Mutate\Mutators\Casting\RemoveObjectCast;
use Pest\Mutate\Mutators\Casting\RemoveStringCast;
use Pest\Mutate\Mutators\ControlStructures\BreakToContinue;
use Pest\Mutate\Mutators\ControlStructures\ContinueToBreak;
use Pest\Mutate\Mutators\ControlStructures\DoWhileAlwaysFalse;
use Pest\Mutate\Mutators\ControlStructures\ElseIfNegated;
use Pest\Mutate\Mutators\ControlStructures\ForAlwaysFalse;
use Pest\Mutate\Mutators\ControlStructures\ForeachEmptyIterable;
use Pest\Mutate\Mutators\ControlStructures\IfNegated;
use Pest\Mutate\Mutators\ControlStructures\TernaryNegated;
use Pest\Mutate\Mutators\ControlStructures\WhileAlwaysFalse;
use Pest\Mutate\Mutators\Equality\EqualToIdentical;
use Pest\Mutate\Mutators\Equality\EqualToNotEqual;
use Pest\Mutate\Mutators\Equality\GreaterOrEqualToGreater;
use Pest\Mutate\Mutators\Equality\GreaterOrEqualToSmaller;
use Pest\Mutate\Mutators\Equality\GreaterToGreaterOrEqual;
use Pest\Mutate\Mutators\Equality\GreaterToSmallerOrEqual;
use Pest\Mutate\Mutators\Equality\IdenticalToEqual;
use Pest\Mutate\Mutators\Equality\IdenticalToNotIdentical;
use Pest\Mutate\Mutators\Equality\NotEqualToEqual;
use Pest\Mutate\Mutators\Equality\NotEqualToNotIdentical;
use Pest\Mutate\Mutators\Equality\NotIdenticalToIdentical;
use Pest\Mutate\Mutators\Equality\NotIdenticalToNotEqual;
use Pest\Mutate\Mutators\Equality\SmallerOrEqualToGreater;
use Pest\Mutate\Mutators\Equality\SmallerOrEqualToSmaller;
use Pest\Mutate\Mutators\Equality\SmallerToGreaterOrEqual;
use Pest\Mutate\Mutators\Equality\SmallerToSmallerOrEqual;
use Pest\Mutate\Mutators\Equality\SpaceshipSwitchSides;
use Pest\Mutate\Mutators\Laravel\Remove\LaravelRemoveStringableUpper;
use Pest\Mutate\Mutators\Laravel\Unwrap\LaravelUnwrapStrUpper;
use Pest\Mutate\Mutators\Logical\BooleanAndToBooleanOr;
use Pest\Mutate\Mutators\Logical\BooleanOrToBooleanAnd;
use Pest\Mutate\Mutators\Logical\CoalesceRemoveLeft;
use Pest\Mutate\Mutators\Logical\FalseToTrue;
use Pest\Mutate\Mutators\Logical\InstanceOfToFalse;
use Pest\Mutate\Mutators\Logical\InstanceOfToTrue;
use Pest\Mutate\Mutators\Logical\LogicalAndToLogicalOr;
use Pest\Mutate\Mutators\Logical\LogicalOrToLogicalAnd;
use Pest\Mutate\Mutators\Logical\LogicalXorToLogicalAnd;
use Pest\Mutate\Mutators\Logical\RemoveNot;
use Pest\Mutate\Mutators\Logical\TrueToFalse;
use Pest\Mutate\Mutators\Math\CeilToFloor;
use Pest\Mutate\Mutators\Math\CeilToRound;
use Pest\Mutate\Mutators\Math\FloorToCiel;
use Pest\Mutate\Mutators\Math\FloorToRound;
use Pest\Mutate\Mutators\Math\MaxToMin;
use Pest\Mutate\Mutators\Math\MinToMax;
use Pest\Mutate\Mutators\Math\RoundToCeil;
use Pest\Mutate\Mutators\Math\RoundToFloor;
use Pest\Mutate\Mutators\Number\DecrementFloat;
use Pest\Mutate\Mutators\Number\DecrementInteger;
use Pest\Mutate\Mutators\Number\IncrementFloat;
use Pest\Mutate\Mutators\Number\IncrementInteger;
use Pest\Mutate\Mutators\Removal\RemoveArrayItem;
use Pest\Mutate\Mutators\Removal\RemoveEarlyReturn;
use Pest\Mutate\Mutators\Removal\RemoveFunctionCall;
use Pest\Mutate\Mutators\Removal\RemoveMethodCall;
use Pest\Mutate\Mutators\Removal\RemoveNullSafeOperator;
use Pest\Mutate\Mutators\Return\AlwaysReturnEmptyArray;
use Pest\Mutate\Mutators\Return\AlwaysReturnNull;
use Pest\Mutate\Mutators\String\ConcatRemoveLeft;
use Pest\Mutate\Mutators\String\ConcatRemoveRight;
use Pest\Mutate\Mutators\String\ConcatSwitchSides;
use Pest\Mutate\Mutators\String\EmptyStringToNotEmpty;
use Pest\Mutate\Mutators\String\NotEmptyStringToEmpty;
use Pest\Mutate\Mutators\String\StrEndsWithToStrStartsWith;
use Pest\Mutate\Mutators\String\StrStartsWithToStrEndsWith;
use Pest\Mutate\Mutators\String\UnwrapChop;
use Pest\Mutate\Mutators\String\UnwrapChunkSplit;
use Pest\Mutate\Mutators\String\UnwrapHtmlentities;
use Pest\Mutate\Mutators\String\UnwrapHtmlEntityDecode;
use Pest\Mutate\Mutators\String\UnwrapHtmlspecialchars;
use Pest\Mutate\Mutators\String\UnwrapHtmlspecialcharsDecode;
use Pest\Mutate\Mutators\String\UnwrapLcfirst;
use Pest\Mutate\Mutators\String\UnwrapLtrim;
use Pest\Mutate\Mutators\String\UnwrapMd5;
use Pest\Mutate\Mutators\String\UnwrapNl2br;
use Pest\Mutate\Mutators\String\UnwrapRtrim;
use Pest\Mutate\Mutators\String\UnwrapStripTags;
use Pest\Mutate\Mutators\String\UnwrapStrIreplace;
use Pest\Mutate\Mutators\String\UnwrapStrPad;
use Pest\Mutate\Mutators\String\UnwrapStrRepeat;
use Pest\Mutate\Mutators\String\UnwrapStrReplace;
use Pest\Mutate\Mutators\String\UnwrapStrrev;
use Pest\Mutate\Mutators\String\UnwrapStrShuffle;
use Pest\Mutate\Mutators\String\UnwrapStrtolower;
use Pest\Mutate\Mutators\String\UnwrapStrtoupper;
use Pest\Mutate\Mutators\String\UnwrapSubstr;
use Pest\Mutate\Mutators\String\UnwrapTrim;
use Pest\Mutate\Mutators\String\UnwrapUcfirst;
use Pest\Mutate\Mutators\String\UnwrapUcwords;
use Pest\Mutate\Mutators\String\UnwrapWordwrap;
use Pest\Mutate\Mutators\Visibility\ConstantProtectedToPrivate;
use Pest\Mutate\Mutators\Visibility\ConstantPublicToProtected;
use Pest\Mutate\Mutators\Visibility\FunctionProtectedToPrivate;
use Pest\Mutate\Mutators\Visibility\FunctionPublicToProtected;
use Pest\Mutate\Mutators\Visibility\PropertyProtectedToPrivate;
use Pest\Mutate\Mutators\Visibility\PropertyPublicToProtected;

/**
 * The family of every mutator pest-plugin-mutate has, by its class. A mutator
 * that makes no change a family's hint describes is marked as having none.
 */
final readonly class Families
{
    private const array FAMILIES = [
        BitwiseAndToBitwiseOr::class => MutatorFamily::Arithmetic,
        BitwiseOrToBitwiseAnd::class => MutatorFamily::Arithmetic,
        BitwiseXorToBitwiseAnd::class => MutatorFamily::Arithmetic,
        DivisionToMultiplication::class => MutatorFamily::Arithmetic,
        MinusToPlus::class => MutatorFamily::Arithmetic,
        ModulusToMultiplication::class => MutatorFamily::Arithmetic,
        MultiplicationToDivision::class => MutatorFamily::Arithmetic,
        PlusToMinus::class => MutatorFamily::Arithmetic,
        PostDecrementToPostIncrement::class => MutatorFamily::Arithmetic,
        PostIncrementToPostDecrement::class => MutatorFamily::Arithmetic,
        PowerToMultiplication::class => MutatorFamily::Arithmetic,
        PreDecrementToPreIncrement::class => MutatorFamily::Arithmetic,
        PreIncrementToPreDecrement::class => MutatorFamily::Arithmetic,
        ShiftLeftToShiftRight::class => MutatorFamily::Arithmetic,
        ShiftRightToShiftLeft::class => MutatorFamily::Arithmetic,
        ArrayKeyFirstToArrayKeyLast::class => MutatorFamily::Collection,
        ArrayKeyLastToArrayKeyFirst::class => MutatorFamily::Collection,
        ArrayPopToArrayShift::class => MutatorFamily::Collection,
        ArrayShiftToArrayPop::class => MutatorFamily::Collection,
        UnwrapArrayChangeKeyCase::class => MutatorFamily::Unwrap,
        UnwrapArrayChunk::class => MutatorFamily::Unwrap,
        UnwrapArrayColumn::class => MutatorFamily::Unwrap,
        UnwrapArrayCombine::class => MutatorFamily::Unwrap,
        UnwrapArrayCountValues::class => MutatorFamily::Unwrap,
        UnwrapArrayDiff::class => MutatorFamily::Unwrap,
        UnwrapArrayDiffAssoc::class => MutatorFamily::Unwrap,
        UnwrapArrayDiffKey::class => MutatorFamily::Unwrap,
        UnwrapArrayDiffUassoc::class => MutatorFamily::Unwrap,
        UnwrapArrayDiffUkey::class => MutatorFamily::Unwrap,
        UnwrapArrayFilter::class => MutatorFamily::Unwrap,
        UnwrapArrayFlip::class => MutatorFamily::Unwrap,
        UnwrapArrayIntersect::class => MutatorFamily::Unwrap,
        UnwrapArrayIntersectAssoc::class => MutatorFamily::Unwrap,
        UnwrapArrayIntersectKey::class => MutatorFamily::Unwrap,
        UnwrapArrayIntersectUassoc::class => MutatorFamily::Unwrap,
        UnwrapArrayIntersectUkey::class => MutatorFamily::Unwrap,
        UnwrapArrayKeys::class => MutatorFamily::Unwrap,
        UnwrapArrayMap::class => MutatorFamily::Unwrap,
        UnwrapArrayMerge::class => MutatorFamily::Unwrap,
        UnwrapArrayMergeRecursive::class => MutatorFamily::Unwrap,
        UnwrapArrayPad::class => MutatorFamily::Unwrap,
        UnwrapArrayReduce::class => MutatorFamily::Unwrap,
        UnwrapArrayReplace::class => MutatorFamily::Unwrap,
        UnwrapArrayReplaceRecursive::class => MutatorFamily::Unwrap,
        UnwrapArrayReverse::class => MutatorFamily::Unwrap,
        UnwrapArraySlice::class => MutatorFamily::Unwrap,
        UnwrapArraySplice::class => MutatorFamily::Unwrap,
        UnwrapArrayUdiff::class => MutatorFamily::Unwrap,
        UnwrapArrayUdiffAssoc::class => MutatorFamily::Unwrap,
        UnwrapArrayUdiffUassoc::class => MutatorFamily::Unwrap,
        UnwrapArrayUintersect::class => MutatorFamily::Unwrap,
        UnwrapArrayUintersectAssoc::class => MutatorFamily::Unwrap,
        UnwrapArrayUintersectUassoc::class => MutatorFamily::Unwrap,
        UnwrapArrayUnique::class => MutatorFamily::Unwrap,
        UnwrapArrayValues::class => MutatorFamily::Unwrap,
        \Pest\Mutate\Mutators\Assignment\BitwiseAndToBitwiseOr::class => MutatorFamily::Arithmetic,
        \Pest\Mutate\Mutators\Assignment\BitwiseOrToBitwiseAnd::class => MutatorFamily::Arithmetic,
        \Pest\Mutate\Mutators\Assignment\BitwiseXorToBitwiseAnd::class => MutatorFamily::Arithmetic,
        CoalesceEqualToEqual::class => MutatorFamily::Logical,
        ConcatEqualToEqual::class => MutatorFamily::None,
        DivideEqualToMultiplyEqual::class => MutatorFamily::Arithmetic,
        MinusEqualToPlusEqual::class => MutatorFamily::Arithmetic,
        ModulusEqualToMultiplyEqual::class => MutatorFamily::Arithmetic,
        MultiplyEqualToDivideEqual::class => MutatorFamily::Arithmetic,
        PlusEqualToMinusEqual::class => MutatorFamily::Arithmetic,
        PowerEqualToMultiplyEqual::class => MutatorFamily::Arithmetic,
        \Pest\Mutate\Mutators\Assignment\ShiftLeftToShiftRight::class => MutatorFamily::Arithmetic,
        \Pest\Mutate\Mutators\Assignment\ShiftRightToShiftLeft::class => MutatorFamily::Arithmetic,
        RemoveArrayCast::class => MutatorFamily::Unwrap,
        RemoveBooleanCast::class => MutatorFamily::Unwrap,
        RemoveDoubleCast::class => MutatorFamily::Unwrap,
        RemoveIntegerCast::class => MutatorFamily::Unwrap,
        RemoveObjectCast::class => MutatorFamily::Unwrap,
        RemoveStringCast::class => MutatorFamily::Unwrap,
        BreakToContinue::class => MutatorFamily::Collection,
        ContinueToBreak::class => MutatorFamily::Collection,
        DoWhileAlwaysFalse::class => MutatorFamily::Collection,
        ElseIfNegated::class => MutatorFamily::Condition,
        ForAlwaysFalse::class => MutatorFamily::Collection,
        ForeachEmptyIterable::class => MutatorFamily::Collection,
        IfNegated::class => MutatorFamily::Condition,
        TernaryNegated::class => MutatorFamily::Condition,
        WhileAlwaysFalse::class => MutatorFamily::Collection,
        EqualToIdentical::class => MutatorFamily::Condition,
        EqualToNotEqual::class => MutatorFamily::Condition,
        GreaterOrEqualToGreater::class => MutatorFamily::Boundary,
        GreaterOrEqualToSmaller::class => MutatorFamily::Condition,
        GreaterToGreaterOrEqual::class => MutatorFamily::Boundary,
        GreaterToSmallerOrEqual::class => MutatorFamily::Condition,
        IdenticalToEqual::class => MutatorFamily::Condition,
        IdenticalToNotIdentical::class => MutatorFamily::Condition,
        NotEqualToEqual::class => MutatorFamily::Condition,
        NotEqualToNotIdentical::class => MutatorFamily::Condition,
        NotIdenticalToIdentical::class => MutatorFamily::Condition,
        NotIdenticalToNotEqual::class => MutatorFamily::Condition,
        SmallerOrEqualToGreater::class => MutatorFamily::Condition,
        SmallerOrEqualToSmaller::class => MutatorFamily::Boundary,
        SmallerToGreaterOrEqual::class => MutatorFamily::Condition,
        SmallerToSmallerOrEqual::class => MutatorFamily::Boundary,
        SpaceshipSwitchSides::class => MutatorFamily::Condition,
        LaravelRemoveStringableUpper::class => MutatorFamily::RemovedCall,
        LaravelUnwrapStrUpper::class => MutatorFamily::Unwrap,
        BooleanAndToBooleanOr::class => MutatorFamily::Logical,
        BooleanOrToBooleanAnd::class => MutatorFamily::Logical,
        CoalesceRemoveLeft::class => MutatorFamily::Logical,
        FalseToTrue::class => MutatorFamily::Literal,
        InstanceOfToFalse::class => MutatorFamily::Condition,
        InstanceOfToTrue::class => MutatorFamily::Condition,
        LogicalAndToLogicalOr::class => MutatorFamily::Logical,
        LogicalOrToLogicalAnd::class => MutatorFamily::Logical,
        LogicalXorToLogicalAnd::class => MutatorFamily::Logical,
        RemoveNot::class => MutatorFamily::Condition,
        TrueToFalse::class => MutatorFamily::Literal,
        CeilToFloor::class => MutatorFamily::Arithmetic,
        CeilToRound::class => MutatorFamily::Arithmetic,
        FloorToCiel::class => MutatorFamily::Arithmetic,
        FloorToRound::class => MutatorFamily::Arithmetic,
        MaxToMin::class => MutatorFamily::Boundary,
        MinToMax::class => MutatorFamily::Boundary,
        RoundToCeil::class => MutatorFamily::Arithmetic,
        RoundToFloor::class => MutatorFamily::Arithmetic,
        DecrementFloat::class => MutatorFamily::Literal,
        DecrementInteger::class => MutatorFamily::Literal,
        IncrementFloat::class => MutatorFamily::Literal,
        IncrementInteger::class => MutatorFamily::Literal,
        RemoveArrayItem::class => MutatorFamily::Collection,
        RemoveEarlyReturn::class => MutatorFamily::ReturnValue,
        RemoveFunctionCall::class => MutatorFamily::RemovedCall,
        RemoveMethodCall::class => MutatorFamily::RemovedCall,
        RemoveNullSafeOperator::class => MutatorFamily::Condition,
        AlwaysReturnEmptyArray::class => MutatorFamily::ReturnValue,
        AlwaysReturnNull::class => MutatorFamily::ReturnValue,
        ConcatRemoveLeft::class => MutatorFamily::None,
        ConcatRemoveRight::class => MutatorFamily::None,
        ConcatSwitchSides::class => MutatorFamily::None,
        EmptyStringToNotEmpty::class => MutatorFamily::Literal,
        NotEmptyStringToEmpty::class => MutatorFamily::Literal,
        StrEndsWithToStrStartsWith::class => MutatorFamily::Condition,
        StrStartsWithToStrEndsWith::class => MutatorFamily::Condition,
        UnwrapChop::class => MutatorFamily::Unwrap,
        UnwrapChunkSplit::class => MutatorFamily::Unwrap,
        UnwrapHtmlEntityDecode::class => MutatorFamily::Unwrap,
        UnwrapHtmlentities::class => MutatorFamily::Unwrap,
        UnwrapHtmlspecialchars::class => MutatorFamily::Unwrap,
        UnwrapHtmlspecialcharsDecode::class => MutatorFamily::Unwrap,
        UnwrapLcfirst::class => MutatorFamily::Unwrap,
        UnwrapLtrim::class => MutatorFamily::Unwrap,
        UnwrapMd5::class => MutatorFamily::Unwrap,
        UnwrapNl2br::class => MutatorFamily::Unwrap,
        UnwrapRtrim::class => MutatorFamily::Unwrap,
        UnwrapStrIreplace::class => MutatorFamily::Unwrap,
        UnwrapStrPad::class => MutatorFamily::Unwrap,
        UnwrapStrRepeat::class => MutatorFamily::Unwrap,
        UnwrapStrReplace::class => MutatorFamily::Unwrap,
        UnwrapStrShuffle::class => MutatorFamily::Unwrap,
        UnwrapStripTags::class => MutatorFamily::Unwrap,
        UnwrapStrrev::class => MutatorFamily::Unwrap,
        UnwrapStrtolower::class => MutatorFamily::Unwrap,
        UnwrapStrtoupper::class => MutatorFamily::Unwrap,
        UnwrapSubstr::class => MutatorFamily::Unwrap,
        UnwrapTrim::class => MutatorFamily::Unwrap,
        UnwrapUcfirst::class => MutatorFamily::Unwrap,
        UnwrapUcwords::class => MutatorFamily::Unwrap,
        UnwrapWordwrap::class => MutatorFamily::Unwrap,
        ConstantProtectedToPrivate::class => MutatorFamily::Visibility,
        ConstantPublicToProtected::class => MutatorFamily::Visibility,
        FunctionProtectedToPrivate::class => MutatorFamily::Visibility,
        FunctionPublicToProtected::class => MutatorFamily::Visibility,
        PropertyProtectedToPrivate::class => MutatorFamily::Visibility,
        PropertyPublicToProtected::class => MutatorFamily::Visibility,
    ];

    /** A mutator's family; one this table does not know has an unknown one. */
    public static function of(string $mutator): MutatorFamily
    {
        return array_key_exists($mutator, self::FAMILIES) ? self::FAMILIES[$mutator] : MutatorFamily::Unknown;
    }

    /** Whether this table names a mutator, with a family or explicitly with none. */
    public static function knows(string $mutator): bool
    {
        return array_key_exists($mutator, self::FAMILIES);
    }
}
