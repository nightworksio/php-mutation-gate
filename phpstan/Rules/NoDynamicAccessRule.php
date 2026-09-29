<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function array_any;
use function is_string;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;
use function str_ends_with;

/**
 * P2 — a name in this codebase is written down.
 *
 * `new $class`, `$object->$property`, `$class::method()` and `$$name` put what
 * is reached for beyond the analyser and beyond every layer rule, which read the
 * names a file writes. A class name read at runtime is allowed only in the files
 * the configuration names, where reading it is the file's whole purpose.
 *
 * @implements Rule<Expr>
 */
final readonly class NoDynamicAccessRule implements Rule
{
    /** @param list<string> $classNamedAtRuntimeIn files, relative to the repository, that may construct or call a class by a name they read */
    public function __construct(private array $classNamedAtRuntimeIn = [])
    {
    }

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $what = $this->dynamicPart($node, $scope);

        if ($what === '') {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'P2 — %s is chosen at runtime, so neither the analyser nor the layer rules can read it. Where the choice is real, make it a match over an enum (P2, D4).',
                $what,
            ))
                ->identifier('mutationGate.dynamicAccess')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    private function dynamicPart(Node $node, Scope $scope): string
    {
        if ($node instanceof Variable && ! is_string($node->name)) {
            return 'this variable variable';
        }

        if ($node instanceof PropertyFetch && ! $node->name instanceof Identifier) {
            return 'this property name';
        }

        return $this->namesAClassAtRuntime($node) && ! $this->mayNameAClassAtRuntime($scope) ? 'this class name' : '';
    }

    private function mayNameAClassAtRuntime(Scope $scope): bool
    {
        return array_any(
            $this->classNamedAtRuntimeIn,
            static fn(string $file): bool => str_ends_with($scope->getFile(), sprintf('/%s', $file)),
        );
    }

    private function namesAClassAtRuntime(Node $node): bool
    {
        return match (true) {
            $node instanceof New_ => ! $node->class instanceof Name && ! $node->class instanceof Class_,
            $node instanceof StaticCall => ! $node->class instanceof Name,
            default => false,
        };
    }
}
