<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function str_contains;

/**
 * C8 — `?->` in the core guards a null that cannot be there.
 *
 * Nothing the core is handed is null (C2), so a `?->` in it is either dead
 * defence or a null that got in anyway. An adapter is where a foreign null
 * arrives and becomes a value the core can name, and it keeps the operator.
 *
 * @implements Rule<Expr>
 */
final class NoNullsafeInCoreRule implements Rule
{
    public function getNodeType(): string
    {
        return Expr::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof NullsafeMethodCall && ! $node instanceof NullsafePropertyFetch) {
            return [];
        }

        if (! str_contains($scope->getFile(), '/src/Core/')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'C8 — nothing the core is handed is null, so ?-> here defends against a case that cannot happen, or a null got in where C2 says one cannot. Turn the foreign null into a value in the adapter that received it (C8, C2).',
            )
                ->identifier('mutationGate.nullsafeInCore')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
