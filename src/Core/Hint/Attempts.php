<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Hint;

use function array_all;
use function array_keys;
use function implode;
use function in_array;

use NightWorksIO\MutationGate\Core\File\Contents;
use NightWorksIO\MutationGate\Core\File\Missing;
use NightWorksIO\MutationGate\Core\Mutant\Mutant;
use NightWorksIO\MutationGate\Core\Mutant\MutatorFamily;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Php\Enclosing;
use NightWorksIO\MutationGate\Core\Php\Nameless;

use function preg_replace;
use function sprintf;

/**
 * The attempt rules build for a survivor (ADR-0101), from its diff, the
 * function around it and the values its file declares; none where no rule
 * reads a value, so the hint and the stub keep their placeholders. A
 * boundary is tried at its value and one step past it, an equality at the
 * value it compares, arithmetic with operands that are neither 0, 1 nor
 * each other, and a logical operator with exactly one side true; a changed
 * constant, a returned literal and a removed call are {@see Statements}'.
 *
 * @internal
 */
final readonly class Attempts
{
    private const array EQUALITIES = ['===', '!==', '==', '!='];

    private const array ARITHMETIC = ['+', '-', '*', '/', '%', '**'];

    private const array LOGICAL = ['&&', '||', 'and', 'or'];

    /** A statement's keyword and its condition's brackets, which a logical operator's sides are inside. */
    private const string AROUND = '/^(?:return\s+|(?:else)?if\s*\(|while\s*\()?(.*?)(?:\)\s*\{|\)|;)?$/su';

    private const string CALL = 'call `%s()` with %s';

    private const string USE = 'use %s';

    private const string AT = '`%s` at %s';

    public static function for(Mutant $mutant, Contents|Missing $source): Attempt|NotGiven
    {
        $change = Change::of($mutant->mutation()->diff());
        $function = $source instanceof Contents
            ? Enclosing::in($source, $mutant->location()->start())
            : Nameless::code();
        $expression = Operands::of($change->expression(), self::operatorsOf($mutant->mutation()->family()));

        return match ($mutant->mutation()->family()) {
            MutatorFamily::Boundary => Compared::boundary($expression, $source, $function),
            MutatorFamily::Condition => Compared::equality($expression, $function),
            MutatorFamily::Arithmetic => Compared::arithmetic($expression, $function),
            MutatorFamily::Logical => Compared::logical(self::sides($change->removed()), $function),
            MutatorFamily::Literal => Statements::constant($change, $mutant, $source),
            MutatorFamily::ReturnValue => Statements::returned($change, $function),
            MutatorFamily::RemovedCall => Statements::removed($change, $function),
            MutatorFamily::Collection,
            MutatorFamily::Exception,
            MutatorFamily::Unwrap,
            MutatorFamily::Visibility,
            MutatorFamily::None,
            MutatorFamily::Unknown => NotGiven::value(),
        };
    }

    /**
     * The attempt that calls the function with these arguments, each call's
     * by the parameter's name, or uses them where one is not its parameter,
     * and then makes no call.
     *
     * @param array<string, string> ...$calls
     */
    public static function calling(Enclosing|Nameless $function, string $then, array ...$calls): Attempt
    {
        $first = $calls[0];
        $pairs = [];

        foreach ($first as $variable => $value) {
            $pairs[] = sprintf(self::AT, $variable, $value);
        }

        $parameters = $function instanceof Enclosing ? $function->parameterNames() : [];
        $passed = $function instanceof Enclosing && array_all(
            array_keys($first),
            static fn(string $name): bool => in_array($name, $parameters, strict: true),
        );
        $arguments = implode(' and ', $pairs);
        $text = $passed ? sprintf(self::CALL, $function->name(), $arguments) : sprintf(self::USE, $arguments);
        $said = sprintf('%s%s', $text, $then);

        return $passed ? Attempt::of($said, ...$calls) : Attempt::of($said);
    }

    /** @return list<string> */
    private static function operatorsOf(MutatorFamily $family): array
    {
        return match ($family) {
            MutatorFamily::Boundary => Comparison::spelt(),
            MutatorFamily::Condition => self::EQUALITIES,
            MutatorFamily::Arithmetic => self::ARITHMETIC,
            MutatorFamily::Literal,
            MutatorFamily::ReturnValue,
            MutatorFamily::RemovedCall,
            MutatorFamily::Logical,
            MutatorFamily::Collection,
            MutatorFamily::Exception,
            MutatorFamily::Unwrap,
            MutatorFamily::Visibility,
            MutatorFamily::None,
            MutatorFamily::Unknown => [],
        };
    }

    /** A removed line's two sides around its logical operator, its keyword and condition's brackets left out. */
    private static function sides(string $removed): Operands|NotGiven
    {
        return Operands::of((string) preg_replace(self::AROUND, '$1', $removed), self::LOGICAL);
    }
}
