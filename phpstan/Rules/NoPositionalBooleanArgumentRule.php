<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function in_array;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function strtolower;

/**
 * D5 — a bare `true` at a call site says nothing.
 *
 * `$this->plan($trees, true)` does not say what is true. Name the argument, or
 * split the method so the branch is not a runtime decision. The call site is
 * read rather than the declaration, because a `bool` parameter of PHP's own can
 * always be named where it is called.
 *
 * @implements Rule<Arg>
 */
final class NoPositionalBooleanArgumentRule implements Rule
{
    public function getNodeType(): string
    {
        return Arg::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->name instanceof Identifier || ! $node->value instanceof ConstFetch) {
            return [];
        }

        if (! in_array(strtolower($node->value->name->toString()), ['true', 'false'], strict: true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'D5 — name this argument or split the method. A bare true or false says nothing about what is true, and a boolean parameter is usually two behaviours sharing one name. Write it as a named argument, strict: true, or give each behaviour its own method (D5).',
            )
                ->identifier('mutationGate.positionalBoolean')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
