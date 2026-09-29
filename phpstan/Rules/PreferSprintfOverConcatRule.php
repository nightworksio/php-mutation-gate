<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function str_contains;

/**
 * H5 — a string with a value in it is built with sprintf, and a message is one
 * literal.
 *
 * Concatenation interleaves the shape of a string with its values. Two literals
 * joined by a dot are three mutants that survive unless a test asserts the whole
 * sentence, so outside the tests a message is one literal. `'a' . 'b' . $c`
 * parses as `Concat(Concat('a', 'b'), $c)`, and the report lands on the first
 * node where a value joins, once per chain.
 *
 * @implements Rule<Concat>
 */
final class PreferSprintfOverConcatRule implements Rule
{
    public function getNodeType(): string
    {
        return Concat::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($this->carriesTheReport($node)) {
            return [
                RuleErrorBuilder::message(
                    'H5 — build this with sprintf rather than concatenation. Concatenation spreads the shape of the string across the values going into it; sprintf keeps it in one literal (H5).',
                )
                    ->identifier('mutationGate.preferSprintf')
                    ->line($node->getStartLine())
                    ->build(),
            ];
        }

        if (! $this->joinsTwoLiterals($node) || str_contains($scope->getFile(), '/tests/')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'H5 — write this as one literal rather than two joined by a dot. Every join between two literals is three mutants that survive unless a test asserts the whole sentence word for word (H5).',
            )
                ->identifier('mutationGate.oneLiteral')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    /** The innermost pair of a chain of literals, so a chain is reported once. */
    private function joinsTwoLiterals(Concat $node): bool
    {
        return ! $node->left instanceof Concat && $node->left instanceof String_ && $node->right instanceof String_;
    }

    /** Whether this node is where the first value joins its chain. */
    private function carriesTheReport(Concat $node): bool
    {
        if (! $node->left instanceof Concat) {
            return !$node->left instanceof String_ || !$node->right instanceof String_;
        }

        return $this->isLiteralText($node->left) && ! $node->right instanceof String_;
    }

    private function isLiteralText(Expr $expr): bool
    {
        if ($expr instanceof String_) {
            return true;
        }

        return $expr instanceof Concat && $this->isLiteralText($expr->left) && $this->isLiteralText($expr->right);
    }
}
