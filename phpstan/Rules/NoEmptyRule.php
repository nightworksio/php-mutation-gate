<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Empty_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * C7 — `empty()` cannot tell absence from zero.
 *
 * It is true for `null`, `false`, `0`, `'0'`, `''` and `[]`. A tree with no
 * mutants and a tree whose score is zero are different verdicts, and `empty()`
 * reads them the same way.
 *
 * @implements Rule<Empty_>
 */
final class NoEmptyRule implements Rule
{
    public function getNodeType(): string
    {
        return Empty_::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        return [
            RuleErrorBuilder::message(
                'C7 — empty() is true for null, false, 0, "0", "" and [], and a gate that reads "no mutants" and "no score" the same way passes what it never judged. Compare for the one you mean: === [], === 0, === "" (C7).',
            )
                ->identifier('mutationGate.empty')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
