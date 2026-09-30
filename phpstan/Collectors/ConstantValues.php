<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Collectors;

use NightWorksIO\MutationGate\PHPStan\Rules\OneHome\Written;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\EnumCase;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;

use function sprintf;
use function str_contains;

/**
 * D9 — the value of every class constant and backed enum case under `src`,
 * with what declares it and its line, for OneHomePerValueRule to group.
 *
 * A value too plain to have a home is left out (Written::isPlain()), and so is
 * a constant declared as another constant, which refers to that one's home.
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
        if (! str_contains($scope->getFile(), '/src/') || str_contains($scope->getFile(), '/tests/') || ! $scope->isInClass()) {
            return [];
        }

        $class = $scope->getClassReflection()->getName();
        $found = [];

        foreach ($this->valuesDeclaredBy($node) as $name => [$value, $line]) {
            $type = $scope->getType($value);

            if (! $value instanceof ClassConstFetch && $type->isConstantValue()->yes() && ! Written::isPlain(Written::of($type))) {
                $found[] = [Written::of($type), sprintf('%s::%s', $class, $name), $line, $node instanceof EnumCase];
            }
        }

        return $found;
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
