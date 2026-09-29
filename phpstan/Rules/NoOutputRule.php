<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Stmt\Echo_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function str_contains;

/**
 * Q4 — nothing writes to the output stream by itself.
 *
 * What the gate says goes through a Reporter, which is where the console, the
 * JSON report and the GitHub annotations each decide what to write. An `echo`
 * lands in the middle of whatever a reporter is writing, and in a JSON report
 * that is a file nothing can parse.
 *
 * @implements Rule<Node>
 */
final class NoOutputRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof Echo_ && ! $node instanceof Print_) {
            return [];
        }

        if (str_contains($scope->getFile(), '/tests/')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Q4 — this writes to the output stream behind every reporter, and lands in the middle of whatever one of them is writing: in a JSON report, that is a file nothing can parse. Say it through a Reporter (Q4).',
            )
                ->identifier('mutationGate.output')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
