<?php

declare(strict_types=1);

use NightWorksIO\MutationGateDefault\Returns;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

/**
 * Every `return` of a snippet, in the order they stand, each with its parent set.
 *
 * @return list<Return_>
 */
function returnsIn(string $php): array
{
    $statements = new ParserFactory()->createForNewestSupportedVersion()->parse($php) ?? [];
    new NodeTraverser(new ParentConnectingVisitor())->traverse($statements);

    return array_values(new NodeFinder()->findInstanceOf($statements, Return_::class));
}

it('finds a return early where another return of its method follows it', function (): void {
    [$inIf, $inLoop, $inElse, $last] = returnsIn(<<<'PHP'
        <?php

        final class Money
        {
            public function amount($a)
            {
                if ($a) {
                    return 1;
                }

                foreach ($a as $b) {
                    return 2;
                }

                if ($a) {
                } else {
                    return 3;
                }

                return 4;
            }
        }
        PHP);

    expect(Returns::isEarly($inIf))->toBeTrue()
        ->and(Returns::isEarly($inLoop))->toBeTrue()
        ->and(Returns::isEarly($inElse))->toBeFalse()
        ->and(Returns::isEarly($last))->toBeFalse();
});

it('finds no early return outside a method, nor in a method without statements', function (): void {
    [$inFunction] = returnsIn("<?php\n\nfunction amount()\n{\n    return 1;\n\n    return 2;\n}\n");

    expect(Returns::isEarly($inFunction))->toBeFalse()
        ->and(Returns::isEarly(new Return_()))->toBeFalse();
});

it('returns null in place of a value where the return type allows null', function (): void {
    [$untyped, $nullable, $union, $bare, $already, $typed, $nested, $closure] = returnsIn(<<<'PHP'
        <?php

        function a() { return 1; }
        function b(): ?int { return 1; }
        function c(): int|null { return 1; }
        function d() { return; }
        function e(): ?int { return null; }
        function f(): int { return 1; }
        function g() { if (true) { return 1; } }
        $h = function () { return 1; };
        PHP);

    expect(Returns::mayReturnNull($untyped))->toBeTrue()
        ->and(Returns::mayReturnNull($nullable))->toBeTrue()
        ->and(Returns::mayReturnNull($union))->toBeTrue()
        ->and(Returns::mayReturnNull($bare))->toBeTrue()
        ->and(Returns::mayReturnNull($already))->toBeFalse()
        ->and(Returns::mayReturnNull($typed))->toBeFalse()
        ->and(Returns::mayReturnNull($nested))->toBeFalse()
        ->and(Returns::mayReturnNull($closure))->toBeFalse();
});

it('returns an empty array in place of a value where the function returns an array', function (): void {
    [$array, $union, $already, $untyped, $typed, $nested, $method] = returnsIn(<<<'PHP'
        <?php

        function a(): array { return [1]; }
        function b(): array|false { return [1]; }
        function c(): array { return []; }
        function d() { return [1]; }
        function e(): int { return 1; }
        function f(): array { if (true) { return [1]; } }
        final class G { public function h(): array { return [1]; } }
        PHP);

    expect(Returns::mayReturnAnArray($array))->toBeTrue()
        ->and(Returns::mayReturnAnArray($union))->toBeTrue()
        ->and(Returns::mayReturnAnArray($already))->toBeFalse()
        ->and(Returns::mayReturnAnArray($untyped))->toBeFalse()
        ->and(Returns::mayReturnAnArray($typed))->toBeFalse()
        ->and(Returns::mayReturnAnArray($nested))->toBeFalse()
        ->and(Returns::mayReturnAnArray($method))->toBeTrue();
});
