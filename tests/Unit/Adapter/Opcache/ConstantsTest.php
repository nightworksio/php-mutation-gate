<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Opcache\Constants;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

/**
 * The constant expressions an expression statement writes, as Constants prints them.
 *
 * @return list<string>
 */
function constantsOf(string $expression): array
{
    $constants = new Constants(new Standard());
    $statements = new ParserFactory()->createForNewestSupportedVersion()->parse(sprintf('<?php %s;', $expression));
    new NodeTraverser($constants)->traverse($statements ?? []);

    return $constants->printed();
}

it('prints each constant expression whole, and none inside one already found', function (string $expression, array $printed): void {
    expect(constantsOf($expression))->toBe($printed);
})->with(fn(): array => [
    'an integer' => ['[$x, 1]', ['1']],
    'a float' => ['[$x, 1.5]', ['1.5']],
    'a string' => ['[$x, \'a\']', ["'a'"]],
    'a magic constant' => ['[$x, __CLASS__]', ['__CLASS__']],
    'a constant' => ['[$x, PHP_EOL]', ['PHP_EOL']],
    'a class constant' => ['[$x, Foo::A]', ['Foo::A']],
    'a nested array' => ["[1, 'k' => [2, ...[3]]]", ["[1, 'k' => [2, ...[3]]]"]],
    'arithmetic' => ['1 + 2 * 3', ['1 + 2 * 3']],
    'a negative' => ['-1', ['-1']],
    'a plus' => ['+1', ['+1']],
    'a bitwise not' => ['~1', ['~1']],
    'a not' => ['!1', ['!1']],
    'a cast' => ['(int) 1.5', ['(int) 1.5']],
    'a ternary' => ['true ? 1 : 2', ['true ? 1 : 2']],
    'a short ternary' => ['1 ?: 2', ['1 ?: 2']],
    'a call of constants' => ["strlen('ab')", ["strlen('ab')"]],
    'a read of a constant array' => ['[1, 2][0]', ['[1, 2][0]']],
]);

it('looks inside an expression that reads the program\'s state for the constant expressions it holds', function (string $expression, array $printed): void {
    expect(constantsOf($expression))->toBe($printed);
})->with(fn(): array => [
    'an array with a variable' => ['[[1], $x]', ['[1]']],
    'an array with a variable key' => ['[$k => 1]', ['1']],
    'a sum with a variable on the right' => ['1 + $x', ['1']],
    'a sum with a variable on the left' => ['$x + 1', ['1']],
    'a negated variable' => ['-$x', []],
    'a cast variable' => ['(int) $x', []],
    'a ternary on a variable' => ['$x ? 1 : 2', ['1', '2']],
    'a ternary to a variable' => ['true ? $x : 2', ['true', '2']],
    'a ternary to a variable otherwise' => ['true ? 1 : $x', ['true', '1']],
    'a short ternary of a variable' => ['$x ?: 2', ['2']],
    'a call with a variable' => ['f(1, $x)', ['1']],
    'a call of a variable' => ['$f(1)', ['1']],
    'a callable' => ['strlen(...)', []],
    'a constant of a variable class' => ['$x::A', []],
    'a constant by a variable name' => ['Foo::{$n}', []],
    'a read of a variable' => ['$x[0]', ['0']],
    'a read at a variable' => ['[1][$i]', ['[1]']],
]);
