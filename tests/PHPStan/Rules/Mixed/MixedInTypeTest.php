<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\PHPStan\Rules\Mixed\MixedInType;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * The nodes of a snippet that are typed mixed natively, by their kind and line.
 *
 * @return list<string>
 */
function typedMixed(string $code): array
{
    $nodes = new NodeFinder()->findInstanceOf(new ParserFactory()->createForHostVersion()->parse($code) ?? [], Node::class);

    return array_values(array_map(
        static fn(Node $node): string => sprintf('%s %d', $node->getType(), $node->getStartLine()),
        array_filter($nodes, MixedInType::of(...)),
    ));
}

it('finds a parameter, a return and a property typed mixed', function (): void {
    expect(typedMixed(<<<'PHP'
        <?php
        final class Reader
        {
            private mixed $held;

            public function read(mixed $value, string $at): mixed
            {
                return $value;
            }
        }
        PHP))->toBe(['Stmt_Property 4', 'Stmt_ClassMethod 6', 'Param 6']);
});

it('finds mixed in a closure, an arrow function and a function', function (): void {
    expect(typedMixed(<<<'PHP'
        <?php
        function read(): mixed
        {
            return null;
        }
        $keep = static function (mixed $value): bool {
            return true;
        };
        $read = static fn(string $at): mixed => $at;
        PHP))->toBe(['Stmt_Function 2', 'Param 6', 'Expr_ArrowFunction 9']);
});

it('finds nothing where every type is named', function (): void {
    expect(typedMixed(<<<'PHP'
        <?php
        final class Reader
        {
            private ?string $held = null;

            public function read(int|string $value, callable $then): void
            {
            }
        }
        $read = static fn(string $at): int => 1;
        PHP))->toBe([]);
});
