<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function array_any;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClassPropertyNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;
use function str_ends_with;

/**
 * D11 — a public property is readonly: anything may read it, and only its own
 * class sets it. A static property, a hooked one, and one whose writes are
 * kept to its class (`private(set)`, `protected(set)`) are not public to
 * write. The files named in `allowIn` hold a property PHP itself writes.
 *
 * @implements Rule<ClassPropertyNode>
 */
final readonly class ReadonlyPublicPropertyRule implements Rule
{
    /** @param list<string> $allowIn files, relative to the repository, with a public property PHP itself writes */
    public function __construct(private array $allowIn = [])
    {
    }

    public function getNodeType(): string
    {
        return ClassPropertyNode::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $open = $node->isPublic()
            && ! $node->isReadOnly()
            && ! $node->isStatic()
            && ! $node->hasHooks()
            && ! $node->isPrivateSet()
            && ! $node->isProtectedSet()
            && ! $node->getClassReflection()->isReadOnly();

        if (! $open || $this->isAllowed($scope->getFile())) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'D11 — $%s is public and not readonly, so any code can change it behind its class. Make it readonly, or keep its writes to the class with private(set) (D11).',
                $node->getName(),
            ))
                ->identifier('mutationGate.publicPropertyNotReadonly')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    private function isAllowed(string $file): bool
    {
        return array_any($this->allowIn, static fn(string $allowed): bool => str_ends_with($file, sprintf('/%s', $allowed)));
    }
}
