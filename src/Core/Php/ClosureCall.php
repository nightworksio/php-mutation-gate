<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Core\Php;

use function ltrim;

use NightWorksIO\MutationGate\Core\Hold\Standing;

/**
 * Where a closure stands by what it is handed to: assigned, passed to one of
 * Pest's functions or methods, directly or inside an array, or anything else.
 */
final readonly class ClosureCall
{
    /** How the name of one of Pest's functions is spelt: global, whether written so or not. */
    private const array NAMES = [T_STRING, T_NAME_FULLY_QUALIFIED];

    /** What calls a method rather than a function. */
    private const array CHAINS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

    /** Pest's functions that take a closure, by what the closure becomes. */
    private const array FUNCTIONS = [
        'it' => Standing::TestClosure,
        'test' => Standing::TestClosure,
        'arch' => Standing::TestClosure,
        'describe' => Standing::DescribeClosure,
        'beforeEach' => Standing::HookClosure,
        'afterEach' => Standing::HookClosure,
        'beforeAll' => Standing::HookClosure,
        'afterAll' => Standing::HookClosure,
        'dataset' => Standing::DatasetClosure,
    ];

    /** Pest's methods that take a closure, by what the closure becomes. */
    private const array METHODS = [
        'with' => Standing::DatasetClosure,
        'beforeEach' => Standing::HookClosure,
        'afterEach' => Standing::HookClosure,
        'beforeAll' => Standing::HookClosure,
        'afterAll' => Standing::HookClosure,
    ];

    /** Where the closure whose attributes begin at an index stands. */
    public static function standingOf(Tokens $tokens, int $start): Standing
    {
        if ($tokens->is($start - 1, '=')) {
            return Standing::KeptClosure;
        }

        $opener = $tokens->enclosing($start);

        while ($tokens->is($opener, '[')) {
            $opener = $tokens->enclosing($opener);
        }

        return $tokens->is($opener, '(') && $tokens->is($opener - 1, ...self::NAMES)
            ? self::calledBy($tokens, $opener - 1)
            : Standing::OtherClosure;
    }

    /** Where a closure passed to the function or method named at an index stands. */
    private static function calledBy(Tokens $tokens, int $callee): Standing
    {
        $called = Names::of(ltrim($tokens->text($callee), '\\'));

        foreach ($tokens->is($callee - 1, ...self::CHAINS) ? self::METHODS : self::FUNCTIONS as $name => $standing) {
            if ($called->meet(Names::of($name))) {
                return $standing;
            }
        }

        return Standing::OtherClosure;
    }
}
