<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateDefault;

use NightWorksIO\MutationGate\Extension\Extension;
use NightWorksIO\MutationGate\Extension\Extensions;
use NightWorksIO\MutationGate\Mutator\MutatorSet;
use NightWorksIO\MutationGateDefault\Arithmetic\BitwiseAndToBitwiseOr;
use NightWorksIO\MutationGateDefault\Arithmetic\BitwiseOrToBitwiseAnd;
use NightWorksIO\MutationGateDefault\Arithmetic\BitwiseXorToBitwiseAnd;
use NightWorksIO\MutationGateDefault\Arithmetic\DivisionToMultiplication;
use NightWorksIO\MutationGateDefault\Arithmetic\MinusToPlus;
use NightWorksIO\MutationGateDefault\Arithmetic\ModulusToMultiplication;
use NightWorksIO\MutationGateDefault\Arithmetic\MultiplicationToDivision;
use NightWorksIO\MutationGateDefault\Arithmetic\PlusToMinus;
use NightWorksIO\MutationGateDefault\Arithmetic\PostDecrementToPostIncrement;
use NightWorksIO\MutationGateDefault\Arithmetic\PostIncrementToPostDecrement;
use NightWorksIO\MutationGateDefault\Arithmetic\PowerToMultiplication;
use NightWorksIO\MutationGateDefault\Arithmetic\PreDecrementToPreIncrement;
use NightWorksIO\MutationGateDefault\Arithmetic\PreIncrementToPreDecrement;
use NightWorksIO\MutationGateDefault\Arithmetic\ShiftLeftToShiftRight;
use NightWorksIO\MutationGateDefault\Arithmetic\ShiftRightToShiftLeft;
use NightWorksIO\MutationGateDefault\Array\ArrayKeyFirstToArrayKeyLast;
use NightWorksIO\MutationGateDefault\Array\ArrayKeyLastToArrayKeyFirst;
use NightWorksIO\MutationGateDefault\Array\ArrayPopToArrayShift;
use NightWorksIO\MutationGateDefault\Array\ArrayShiftToArrayPop;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayChangeKeyCase;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayChunk;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayColumn;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayCombine;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayCountValues;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayDiff;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayDiffAssoc;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayDiffKey;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayDiffUassoc;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayDiffUkey;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayFilter;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayFlip;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayIntersect;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayIntersectAssoc;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayIntersectKey;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayIntersectUassoc;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayIntersectUkey;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayKeys;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayMap;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayMerge;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayMergeRecursive;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayPad;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayReduce;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayReplace;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayReplaceRecursive;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayReverse;
use NightWorksIO\MutationGateDefault\Array\UnwrapArraySlice;
use NightWorksIO\MutationGateDefault\Array\UnwrapArraySplice;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayUdiff;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayUdiffAssoc;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayUdiffUassoc;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayUintersect;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayUintersectAssoc;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayUintersectUassoc;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayUnique;
use NightWorksIO\MutationGateDefault\Array\UnwrapArrayValues;
use NightWorksIO\MutationGateDefault\Assignment\BitwiseAndEqualToBitwiseOrEqual;
use NightWorksIO\MutationGateDefault\Assignment\BitwiseOrEqualToBitwiseAndEqual;
use NightWorksIO\MutationGateDefault\Assignment\BitwiseXorEqualToBitwiseAndEqual;
use NightWorksIO\MutationGateDefault\Assignment\CoalesceEqualToEqual;
use NightWorksIO\MutationGateDefault\Assignment\ConcatEqualToEqual;
use NightWorksIO\MutationGateDefault\Assignment\DivideEqualToMultiplyEqual;
use NightWorksIO\MutationGateDefault\Assignment\MinusEqualToPlusEqual;
use NightWorksIO\MutationGateDefault\Assignment\ModulusEqualToMultiplyEqual;
use NightWorksIO\MutationGateDefault\Assignment\MultiplyEqualToDivideEqual;
use NightWorksIO\MutationGateDefault\Assignment\PlusEqualToMinusEqual;
use NightWorksIO\MutationGateDefault\Assignment\PowerEqualToMultiplyEqual;
use NightWorksIO\MutationGateDefault\Assignment\ShiftLeftEqualToShiftRightEqual;
use NightWorksIO\MutationGateDefault\Assignment\ShiftRightEqualToShiftLeftEqual;
use NightWorksIO\MutationGateDefault\Casting\RemoveArrayCast;
use NightWorksIO\MutationGateDefault\Casting\RemoveBooleanCast;
use NightWorksIO\MutationGateDefault\Casting\RemoveDoubleCast;
use NightWorksIO\MutationGateDefault\Casting\RemoveIntegerCast;
use NightWorksIO\MutationGateDefault\Casting\RemoveObjectCast;
use NightWorksIO\MutationGateDefault\Casting\RemoveStringCast;
use NightWorksIO\MutationGateDefault\ControlStructures\BreakToContinue;
use NightWorksIO\MutationGateDefault\ControlStructures\ContinueToBreak;
use NightWorksIO\MutationGateDefault\ControlStructures\DoWhileAlwaysFalse;
use NightWorksIO\MutationGateDefault\ControlStructures\ElseIfNegated;
use NightWorksIO\MutationGateDefault\ControlStructures\ForAlwaysFalse;
use NightWorksIO\MutationGateDefault\ControlStructures\ForeachEmptyIterable;
use NightWorksIO\MutationGateDefault\ControlStructures\IfNegated;
use NightWorksIO\MutationGateDefault\ControlStructures\TernaryNegated;
use NightWorksIO\MutationGateDefault\ControlStructures\WhileAlwaysFalse;
use NightWorksIO\MutationGateDefault\Equality\EqualToIdentical;
use NightWorksIO\MutationGateDefault\Equality\EqualToNotEqual;
use NightWorksIO\MutationGateDefault\Equality\GreaterOrEqualToGreater;
use NightWorksIO\MutationGateDefault\Equality\GreaterOrEqualToSmaller;
use NightWorksIO\MutationGateDefault\Equality\GreaterToGreaterOrEqual;
use NightWorksIO\MutationGateDefault\Equality\GreaterToSmallerOrEqual;
use NightWorksIO\MutationGateDefault\Equality\IdenticalToNotIdentical;
use NightWorksIO\MutationGateDefault\Equality\NotEqualToEqual;
use NightWorksIO\MutationGateDefault\Equality\NotEqualToNotIdentical;
use NightWorksIO\MutationGateDefault\Equality\NotIdenticalToIdentical;
use NightWorksIO\MutationGateDefault\Equality\SmallerOrEqualToGreater;
use NightWorksIO\MutationGateDefault\Equality\SmallerOrEqualToSmaller;
use NightWorksIO\MutationGateDefault\Equality\SmallerToGreaterOrEqual;
use NightWorksIO\MutationGateDefault\Equality\SmallerToSmallerOrEqual;
use NightWorksIO\MutationGateDefault\Equality\SpaceshipSwitchSides;
use NightWorksIO\MutationGateDefault\Exception\RemoveThrow;
use NightWorksIO\MutationGateDefault\Logical\BooleanAndToBooleanOr;
use NightWorksIO\MutationGateDefault\Logical\BooleanOrToBooleanAnd;
use NightWorksIO\MutationGateDefault\Logical\CoalesceRemoveLeft;
use NightWorksIO\MutationGateDefault\Logical\FalseToTrue;
use NightWorksIO\MutationGateDefault\Logical\InstanceOfToFalse;
use NightWorksIO\MutationGateDefault\Logical\InstanceOfToTrue;
use NightWorksIO\MutationGateDefault\Logical\LogicalAndToLogicalOr;
use NightWorksIO\MutationGateDefault\Logical\LogicalOrToLogicalAnd;
use NightWorksIO\MutationGateDefault\Logical\LogicalXorToLogicalAnd;
use NightWorksIO\MutationGateDefault\Logical\RemoveNot;
use NightWorksIO\MutationGateDefault\Logical\TrueToFalse;
use NightWorksIO\MutationGateDefault\Math\CeilToFloor;
use NightWorksIO\MutationGateDefault\Math\CeilToRound;
use NightWorksIO\MutationGateDefault\Math\FloorToCeil;
use NightWorksIO\MutationGateDefault\Math\FloorToRound;
use NightWorksIO\MutationGateDefault\Math\MaxToMin;
use NightWorksIO\MutationGateDefault\Math\MinToMax;
use NightWorksIO\MutationGateDefault\Math\RoundToCeil;
use NightWorksIO\MutationGateDefault\Math\RoundToFloor;
use NightWorksIO\MutationGateDefault\Number\DecrementFloat;
use NightWorksIO\MutationGateDefault\Number\DecrementInteger;
use NightWorksIO\MutationGateDefault\Number\IncrementFloat;
use NightWorksIO\MutationGateDefault\Number\IncrementInteger;
use NightWorksIO\MutationGateDefault\Removal\RemoveArrayItem;
use NightWorksIO\MutationGateDefault\Removal\RemoveEarlyReturn;
use NightWorksIO\MutationGateDefault\Removal\RemoveFunctionCall;
use NightWorksIO\MutationGateDefault\Removal\RemoveMethodCall;
use NightWorksIO\MutationGateDefault\Removal\RemoveNullSafeOperator;
use NightWorksIO\MutationGateDefault\Return\AlwaysReturnEmptyArray;
use NightWorksIO\MutationGateDefault\Return\AlwaysReturnNull;
use NightWorksIO\MutationGateDefault\String\ConcatRemoveLeft;
use NightWorksIO\MutationGateDefault\String\ConcatRemoveRight;
use NightWorksIO\MutationGateDefault\String\ConcatSwitchSides;
use NightWorksIO\MutationGateDefault\String\EmptyStringToNotEmpty;
use NightWorksIO\MutationGateDefault\String\StrEndsWithToStrStartsWith;
use NightWorksIO\MutationGateDefault\String\StrStartsWithToStrEndsWith;
use NightWorksIO\MutationGateDefault\String\UnwrapChop;
use NightWorksIO\MutationGateDefault\String\UnwrapChunkSplit;
use NightWorksIO\MutationGateDefault\String\UnwrapHtmlentities;
use NightWorksIO\MutationGateDefault\String\UnwrapHtmlEntityDecode;
use NightWorksIO\MutationGateDefault\String\UnwrapHtmlspecialchars;
use NightWorksIO\MutationGateDefault\String\UnwrapHtmlspecialcharsDecode;
use NightWorksIO\MutationGateDefault\String\UnwrapLcfirst;
use NightWorksIO\MutationGateDefault\String\UnwrapLtrim;
use NightWorksIO\MutationGateDefault\String\UnwrapMdFive;
use NightWorksIO\MutationGateDefault\String\UnwrapNlToBr;
use NightWorksIO\MutationGateDefault\String\UnwrapRtrim;
use NightWorksIO\MutationGateDefault\String\UnwrapStripTags;
use NightWorksIO\MutationGateDefault\String\UnwrapStrIreplace;
use NightWorksIO\MutationGateDefault\String\UnwrapStrPad;
use NightWorksIO\MutationGateDefault\String\UnwrapStrRepeat;
use NightWorksIO\MutationGateDefault\String\UnwrapStrReplace;
use NightWorksIO\MutationGateDefault\String\UnwrapStrrev;
use NightWorksIO\MutationGateDefault\String\UnwrapStrShuffle;
use NightWorksIO\MutationGateDefault\String\UnwrapStrtolower;
use NightWorksIO\MutationGateDefault\String\UnwrapStrtoupper;
use NightWorksIO\MutationGateDefault\String\UnwrapSubstr;
use NightWorksIO\MutationGateDefault\String\UnwrapTrim;
use NightWorksIO\MutationGateDefault\String\UnwrapUcfirst;
use NightWorksIO\MutationGateDefault\String\UnwrapUcwords;
use NightWorksIO\MutationGateDefault\String\UnwrapWordwrap;
use NightWorksIO\MutationGateDefault\Visibility\PublicToProtected;

/**
 * Registers the `default` set: the mutators of Pest's own default set, and one
 * for each of the Exception and Visibility families it leaves out, written
 * against the gate's SDK.
 */
final readonly class DefaultExtension implements Extension
{
    public function extend(Extensions $extensions): Extensions
    {
        return $extensions->withMutators(
            DefaultSet::name(),
            MutatorSet::of(
                BitwiseAndToBitwiseOr::class,
                BitwiseOrToBitwiseAnd::class,
                BitwiseXorToBitwiseAnd::class,
                PlusToMinus::class,
                MinusToPlus::class,
                DivisionToMultiplication::class,
                MultiplicationToDivision::class,
                ModulusToMultiplication::class,
                PowerToMultiplication::class,
                ShiftLeftToShiftRight::class,
                ShiftRightToShiftLeft::class,
                PostDecrementToPostIncrement::class,
                PostIncrementToPostDecrement::class,
                PreDecrementToPreIncrement::class,
                PreIncrementToPreDecrement::class,
                ArrayKeyFirstToArrayKeyLast::class,
                ArrayKeyLastToArrayKeyFirst::class,
                ArrayPopToArrayShift::class,
                ArrayShiftToArrayPop::class,
                UnwrapArrayChangeKeyCase::class,
                UnwrapArrayChunk::class,
                UnwrapArrayColumn::class,
                UnwrapArrayCombine::class,
                UnwrapArrayCountValues::class,
                UnwrapArrayDiffAssoc::class,
                UnwrapArrayDiffKey::class,
                UnwrapArrayDiffUassoc::class,
                UnwrapArrayDiffUkey::class,
                UnwrapArrayDiff::class,
                UnwrapArrayFilter::class,
                UnwrapArrayFlip::class,
                UnwrapArrayIntersectAssoc::class,
                UnwrapArrayIntersectKey::class,
                UnwrapArrayIntersectUassoc::class,
                UnwrapArrayIntersectUkey::class,
                UnwrapArrayIntersect::class,
                UnwrapArrayKeys::class,
                UnwrapArrayMap::class,
                UnwrapArrayMergeRecursive::class,
                UnwrapArrayMerge::class,
                UnwrapArrayPad::class,
                UnwrapArrayReduce::class,
                UnwrapArrayReplaceRecursive::class,
                UnwrapArrayReplace::class,
                UnwrapArrayReverse::class,
                UnwrapArraySlice::class,
                UnwrapArraySplice::class,
                UnwrapArrayUdiffAssoc::class,
                UnwrapArrayUdiffUassoc::class,
                UnwrapArrayUdiff::class,
                UnwrapArrayUintersectAssoc::class,
                UnwrapArrayUintersectUassoc::class,
                UnwrapArrayUintersect::class,
                UnwrapArrayUnique::class,
                UnwrapArrayValues::class,
                BitwiseAndEqualToBitwiseOrEqual::class,
                BitwiseOrEqualToBitwiseAndEqual::class,
                BitwiseXorEqualToBitwiseAndEqual::class,
                CoalesceEqualToEqual::class,
                ConcatEqualToEqual::class,
                DivideEqualToMultiplyEqual::class,
                MinusEqualToPlusEqual::class,
                ModulusEqualToMultiplyEqual::class,
                MultiplyEqualToDivideEqual::class,
                PlusEqualToMinusEqual::class,
                PowerEqualToMultiplyEqual::class,
                ShiftLeftEqualToShiftRightEqual::class,
                ShiftRightEqualToShiftLeftEqual::class,
                RemoveArrayCast::class,
                RemoveBooleanCast::class,
                RemoveDoubleCast::class,
                RemoveIntegerCast::class,
                RemoveObjectCast::class,
                RemoveStringCast::class,
                IfNegated::class,
                ElseIfNegated::class,
                TernaryNegated::class,
                ForAlwaysFalse::class,
                ForeachEmptyIterable::class,
                DoWhileAlwaysFalse::class,
                WhileAlwaysFalse::class,
                BreakToContinue::class,
                ContinueToBreak::class,
                EqualToNotEqual::class,
                NotEqualToEqual::class,
                IdenticalToNotIdentical::class,
                NotIdenticalToIdentical::class,
                GreaterToGreaterOrEqual::class,
                GreaterToSmallerOrEqual::class,
                GreaterOrEqualToGreater::class,
                GreaterOrEqualToSmaller::class,
                SmallerToGreaterOrEqual::class,
                SmallerToSmallerOrEqual::class,
                SmallerOrEqualToGreater::class,
                SmallerOrEqualToSmaller::class,
                EqualToIdentical::class,
                NotEqualToNotIdentical::class,
                SpaceshipSwitchSides::class,
                BooleanAndToBooleanOr::class,
                BooleanOrToBooleanAnd::class,
                CoalesceRemoveLeft::class,
                LogicalAndToLogicalOr::class,
                LogicalOrToLogicalAnd::class,
                LogicalXorToLogicalAnd::class,
                FalseToTrue::class,
                TrueToFalse::class,
                InstanceOfToTrue::class,
                InstanceOfToFalse::class,
                RemoveNot::class,
                MinToMax::class,
                MaxToMin::class,
                RoundToFloor::class,
                RoundToCeil::class,
                FloorToRound::class,
                FloorToCeil::class,
                CeilToFloor::class,
                CeilToRound::class,
                DecrementFloat::class,
                IncrementFloat::class,
                DecrementInteger::class,
                IncrementInteger::class,
                RemoveArrayItem::class,
                RemoveEarlyReturn::class,
                RemoveFunctionCall::class,
                RemoveMethodCall::class,
                RemoveNullSafeOperator::class,
                AlwaysReturnNull::class,
                AlwaysReturnEmptyArray::class,
                ConcatRemoveLeft::class,
                ConcatRemoveRight::class,
                ConcatSwitchSides::class,
                EmptyStringToNotEmpty::class,
                StrStartsWithToStrEndsWith::class,
                StrEndsWithToStrStartsWith::class,
                UnwrapChop::class,
                UnwrapChunkSplit::class,
                UnwrapHtmlentities::class,
                UnwrapHtmlEntityDecode::class,
                UnwrapHtmlspecialchars::class,
                UnwrapHtmlspecialcharsDecode::class,
                UnwrapLcfirst::class,
                UnwrapLtrim::class,
                UnwrapMdFive::class,
                UnwrapNlToBr::class,
                UnwrapRtrim::class,
                UnwrapStripTags::class,
                UnwrapStrIreplace::class,
                UnwrapStrPad::class,
                UnwrapStrRepeat::class,
                UnwrapStrReplace::class,
                UnwrapStrrev::class,
                UnwrapStrShuffle::class,
                UnwrapStrtolower::class,
                UnwrapStrtoupper::class,
                UnwrapSubstr::class,
                UnwrapTrim::class,
                UnwrapUcfirst::class,
                UnwrapUcwords::class,
                UnwrapWordwrap::class,
                RemoveThrow::class,
                PublicToProtected::class,
            ),
        );
    }
}
