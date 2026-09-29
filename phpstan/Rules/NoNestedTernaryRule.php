<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\Ternary;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function str_contains;

/**
 * C9 — one condition per expression, and no `??` standing in for a shape.
 *
 * A ternary inside a ternary is read twice, and `match` is the same thing with
 * each branch on its own line. `$array['key'] ?? $default` reads as a default
 * and is an admission that nobody knows whether the key is there: a value
 * object knows its own fields, and an untrusted shape is parsed at the edge.
 * Tests read foreign reports and keep the second half.
 *
 * @implements Rule<Node>
 */
final class NoNestedTernaryRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof Ternary && ($node->if instanceof Ternary || $node->else instanceof Ternary)) {
            return [
                RuleErrorBuilder::message(
                    'C9 — a ternary inside a ternary is read twice: once to find where each ends and again to pair a branch with its condition. Write a match (C9).',
                )
                    ->identifier('mutationGate.nestedTernary')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        if (! $node instanceof Coalesce || ! $node->left instanceof ArrayDimFetch || str_contains($scope->getFile(), '/tests/')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'C9 — `??` on an array subscript reads as a default and is an admission that nobody knows whether the key is there. Parse the shape into a value at the edge, where a missing key is refused by name (C9, D1).',
            )
                ->identifier('mutationGate.coalesceOnSubscript')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
