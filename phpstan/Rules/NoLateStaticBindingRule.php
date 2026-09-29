<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Q3 — `static::` describes a subclass that cannot exist.
 *
 * Every class here is final, so late static binding resolves to the class it
 * is written in and sends a reader looking for a subclass that is not there.
 *
 * @implements Rule<Node>
 */
final class NoLateStaticBindingRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->bindsLate($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Q3 — every class here is final, so `static` resolves to the class it is written in and only tells a reader that a subclass exists. Write `self` (Q3).',
            )
                ->identifier('mutationGate.lateStaticBinding')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    private function bindsLate(Node $node): bool
    {
        $class = match (true) {
            $node instanceof StaticCall, $node instanceof ClassConstFetch,
            $node instanceof StaticPropertyFetch, $node instanceof New_ => $node->class,
            default => null,
        };

        return $class instanceof Name && $class->toLowerString() === 'static';
    }
}
