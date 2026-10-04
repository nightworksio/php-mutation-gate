<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel;

use function array_search;
use function array_slice;
use function explode;
use function implode;

use NightWorksIO\MutationGate\Mutator\Ancestors;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;

/**
 * The validation rules Laravel code writes: the list `validate()` or
 * `Validator::make()` is given, each field of it by its name, and each field's
 * rules, as a list or as one string of rules joined by `|`.
 */
final readonly class Rules
{
    private const string VALIDATE = 'validate';

    private const string SEPARATOR = '|';

    /** Where `$request->validate()` takes the rules. */
    private const int FIRST = 0;

    /** Where `$this->validate()` takes the rules, after the request, and `Validator::make()`, after the data. */
    private const int SECOND = 1;

    /** Whether an item is one rule in a field's list of rules. */
    public static function isRuleOfAField(ArrayItem $rule): bool
    {
        $list = Ancestors::parent($rule);
        $field = $list instanceof Array_ ? Ancestors::parent($list) : false;

        return $field instanceof ArrayItem && $field->value === $list && self::isField($field);
    }

    /** Whether a string is a field's rules, such as `'required|email'`. */
    public static function isRulesOfAField(String_ $rules): bool
    {
        $field = Ancestors::parent($rules);

        return $field instanceof ArrayItem && $field->value === $rules && self::isField($field);
    }

    /** A field's rules without the first of them. */
    public static function withoutTheFirst(String_ $rules): String_
    {
        $kept = array_slice(explode(self::SEPARATOR, $rules->value), self::SECOND);

        return new String_(implode(self::SEPARATOR, $kept));
    }

    /** Whether an item is a field, by its name, of the rules a validation is given. */
    private static function isField(ArrayItem $field): bool
    {
        $rules = Ancestors::parent($field);
        $argument = $rules instanceof Array_ ? Ancestors::parent($rules) : false;
        $call = $argument instanceof Arg ? Ancestors::parent($argument) : false;

        return $field->key instanceof Expr
            && ($call instanceof MethodCall || $call instanceof StaticCall)
            && self::rulesAt($call) === array_search($argument, $call->args, strict: true);
    }

    /** Where a call takes the rules it validates by, where it validates. */
    private static function rulesAt(MethodCall|StaticCall $call): int|false
    {
        return match (true) {
            Calls::onThis($call, self::VALIDATE) => self::SECOND,
            $call instanceof MethodCall && Calls::named($call->name, self::VALIDATE) => self::FIRST,
            Calls::onFacade($call, Facade::Validator, 'make') => self::SECOND,
            default => false,
        };
    }
}
