<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function in_array;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;
use function strtolower;

/**
 * P1 — a member exists or it does not.
 *
 * `__get` and `__call` create members at the moment they are asked for, so the
 * analyser sees a class without them and no rule applies to what they expose.
 * `__invoke` has a signature and is not on the list.
 *
 * @implements Rule<ClassMethod>
 */
final class NoMagicMethodRule implements Rule
{
    private const array HIDDEN = ['__get', '__set', '__isset', '__unset', '__call', '__callstatic'];

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! in_array(strtolower($node->name->toString()), self::HIDDEN, strict: true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'P1 — %s() makes a member that only exists at runtime, and the analyser sees a class without it, so no rule here reaches what it exposes. Declare the members (P1).',
                $node->name->toString(),
            ))
                ->identifier('mutationGate.magicMethod')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
