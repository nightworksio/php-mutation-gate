<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGateLaravel;

use function in_array;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\Int_;

/** The calls of `abort_if()` and `abort_unless()`, and among them those that refuse a request. */
final readonly class Aborts
{
    /** The status codes that refuse a request: not signed in, and not allowed. */
    private const array REFUSING = [401, 403];

    /** Where both functions take the status code, after the condition. */
    private const int CODE = 1;

    /** Whether a call stops the request on a condition. */
    public static function stops(Expr $call): bool
    {
        return Calls::ofFunction($call, 'abort_if', 'abort_unless');
    }

    /** Whether a call stops the request on a condition with 401 or 403, written as a number. */
    public static function refuses(Expr $call): bool
    {
        $code = $call instanceof FuncCall && self::stops($call) ? Calls::argument($call, self::CODE) : false;

        return $code instanceof Int_ && in_array($code->value, self::REFUSING, strict: true);
    }
}
