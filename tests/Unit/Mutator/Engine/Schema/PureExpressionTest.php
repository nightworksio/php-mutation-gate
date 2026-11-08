<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Mutator\Engine\Schema\PureExpression;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;

/** The expression a line of PHP holds. */
function pureCandidate(string $code): PhpParser\Node\Expr
{
    $statement = new ParserFactory()->createForNewestSupportedVersion()->parse(sprintf('<?php %s;', $code))[0] ?? null;

    return $statement instanceof Expression ? $statement->expr : throw new LogicException($code);
}

it('is pure where it runs nothing but PHP\'s own operators on literals, constants and variables', function (string $code): void {
    expect(PureExpression::is(pureCandidate($code)))->toBeTrue();
})->with([
    '1 + 2',
    '1.5 * $a',
    "'a' === \$b",
    'PHP_INT_MAX - $a',
    'Money::CENTS % 7',
    '! $a',
    '-$a',
    '+$a',
    '~$a',
    '$a ? 1 : 2',
    '$a ?: 2',
    '$a instanceof Money',
    '(int) $a',
    '(float) $a',
    '(bool) $a',
    '$a <=> $b',
    '$a ?? 0',
    '$a && ($b || ! $c)',
]);

it('is not pure where it can run code of its own, or reads what a closure cannot', function (string $code): void {
    expect(PureExpression::is(pureCandidate($code)))->toBeFalse();
})->with([
    "'a' . \$b",
    '"a{$b}"',
    '(string) $a',
    '(array) $a',
    '(object) $a',
    '$a[0] + 1',
    '$a->b + 1',
    'f($a) + 1',
    '$a->f() + 1',
    'Money::f() + 1',
    '$a = 1',
    '$a += 1',
    '$a++',
    '__LINE__ + 1',
    '$this + 1',
    '$GLOBALS + 1',
    '$$a + 1',
    '$a::B + 1',
    'new Money()',
    '[1, 2]',
    'fn () => 1',
]);
