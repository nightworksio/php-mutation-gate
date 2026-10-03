<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Collectors;

use NightWorksIO\MutationGate\PHPStan\Rules\OneHome\Written;
use NightWorksIO\MutationGate\PHPStan\Rules\OwnSource;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\EnumCase;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

use function sprintf;

/**
 * D9 — the value of every class constant and backed enum case under `src`,
 * with what declares it and its line, for OneHomePerValueRule to group. An
 * array constant is collected whole and item by item, at any depth, each item
 * under the constant's name.
 *
 * A value too plain to have a home is left out (Written::isPlain()), and so is
 * a constant or an item written as another constant, which refers to that
 * one's home.
 *
 * @implements Collector<Node, list<array{string, string, int, bool}>>
 */
final readonly class ConstantValues implements Collector
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @return list<array{string, string, int, bool}> the written value, the declaring constant or case, its line, and whether it is an enum case */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! OwnSource::holds($scope->getFile()) || ! $scope->isInClass()) {
            return [];
        }

        $class = $scope->getClassReflection()->getName();
        $found = [];

        foreach ($this->valuesDeclaredBy($node) as $name => [$value, $line]) {
            foreach ($this->spelledOut($value, $scope) as $written) {
                $found[] = [$written, sprintf('%s::%s', $class, $name), $line, $node instanceof EnumCase];
            }
        }

        return $found;
    }

    /**
     * What a value spells out itself rather than refers to, as written: the
     * value, and every item of an array, at any depth.
     *
     * @return list<string>
     */
    private function spelledOut(Expr $value, Scope $scope): array
    {
        $type = $scope->getType($value);
        $spelled = $this->refersToAHome($value) || ! $type->isConstantValue()->yes() || Written::isPlain(Written::of($type))
            ? []
            : [Written::of($type)];

        foreach ($value instanceof Array_ ? $value->items : [] as $item) {
            $spelled = [...$spelled, ...$this->spelledOut($item->value, $scope)];
        }

        return $spelled;
    }

    /** Whether a value is written as another's home: a class or global constant, or an enum case's value. */
    private function refersToAHome(Expr $value): bool
    {
        return $value instanceof ClassConstFetch
            || $value instanceof ConstFetch
            || ($value instanceof PropertyFetch && $value->var instanceof ClassConstFetch);
    }

    /**
     * The values a constant declaration or an enum case declares, by name.
     *
     * @return array<string, array{Expr, int}>
     */
    private function valuesDeclaredBy(Node $node): array
    {
        $declared = [];

        if ($node instanceof ClassConst) {
            foreach ($node->consts as $constant) {
                $declared[$constant->name->toString()] = [$constant->value, $constant->getStartLine()];
            }
        }

        if ($node instanceof EnumCase && $node->expr instanceof Expr) {
            $declared[$node->name->toString()] = [$node->expr, $node->getStartLine()];
        }

        return $declared;
    }
}
