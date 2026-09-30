<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\Mutator;
use NightWorksIO\MutationGate\Mutator\Unchanged;
use NightWorksIO\MutationGateDefault\Argument;
use NightWorksIO\MutationGateDefault\Calls;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\PrettyPrinter\Standard;

function printedCall(Node|Unchanged $node): string
{
    return $node instanceof Expr ? new Standard()->prettyPrintExpr($node) : 'unchanged';
}

function callOfFunction(Name|Variable $name, Arg|VariadicPlaceholder ...$arguments): FuncCall
{
    return new FuncCall($name, $arguments);
}

it('puts the argument at a position in the place of a call of the function by its bare name', function (): void {
    $a = new Variable('a');
    $b = new Variable('b');

    expect(Calls::unwrapped(callOfFunction(new Name('trim'), new Arg($a), new Arg($b)), 'trim', Argument::First))->toBe($a)
        ->and(Calls::unwrapped(callOfFunction(new FullyQualified('trim'), new Arg($a), new Arg($b)), 'trim', Argument::Second))->toBe($b);
});

it('leaves alone a call of another function, a qualified one, one through a variable, and a missing argument', function (): void {
    $a = new Arg(new Variable('a'));

    expect(Calls::unwrapped(callOfFunction(new Name('rtrim'), $a), 'trim', Argument::First))->toEqual(Unchanged::node())
        ->and(Calls::unwrapped(callOfFunction(new Name('Acme\trim'), $a), 'trim', Argument::First))->toEqual(Unchanged::node())
        ->and(Calls::unwrapped(callOfFunction(new Variable('trim'), $a), 'trim', Argument::First))->toEqual(Unchanged::node())
        ->and(Calls::unwrapped(callOfFunction(new Name('trim'), $a), 'trim', Argument::Second))->toEqual(Unchanged::node())
        ->and(Calls::unwrapped(new Variable('trim'), 'trim', Argument::First))->toEqual(Unchanged::node());
});

it('puts an arrow function that returns its argument in the place of a first-class callable, for its first argument only', function (): void {
    $callable = callOfFunction(new Name('trim'), new VariadicPlaceholder());
    $identity = Calls::unwrapped($callable, 'trim', Argument::First);

    expect($identity)->toBeInstanceOf(ArrowFunction::class)
        ->and(printedCall($identity))->toBe('fn($value) => $value')
        ->and(Calls::unwrapped($callable, 'trim', Argument::Second))->toEqual(Unchanged::node());
});

it('renames a copy of a call of the function by its bare name, and leaves the call as it was', function (): void {
    $floor = callOfFunction(new FullyQualified('floor'), new Arg(new Variable('a')));
    $renamed = Calls::renamed($floor, 'floor', 'ceil');

    expect($renamed)->toBeInstanceOf(FuncCall::class)
        ->and(printedCall($renamed))->toBe('ceil($a)')
        ->and(printedCall($floor))->toBe('\floor($a)')
        ->and(Calls::renamed(callOfFunction(new Name('round'), new Arg(new Variable('a'))), 'floor', 'ceil'))->toEqual(Unchanged::node());
});

it('tells a strict flag of in_array and array_search from any other argument', function (): void {
    $flagOf = static function (Name|Variable $name): ConstFetch {
        $flag = new ConstFetch(new Name('true'));
        $argument = new Arg($flag);
        $flag->setAttribute(Mutator::PARENT, $argument);
        $argument->setAttribute(Mutator::PARENT, callOfFunction($name, $argument));

        return $flag;
    };

    expect(Calls::isStrictFlag($flagOf(new Name('in_array'))))->toBeTrue()
        ->and(Calls::isStrictFlag($flagOf(new Name('array_search'))))->toBeTrue()
        ->and(Calls::isStrictFlag($flagOf(new Name('array_keys'))))->toBeFalse()
        ->and(Calls::isStrictFlag($flagOf(new Variable('in_array'))))->toBeFalse()
        ->and(Calls::isStrictFlag(new ConstFetch(new Name('true'))))->toBeFalse();
});
