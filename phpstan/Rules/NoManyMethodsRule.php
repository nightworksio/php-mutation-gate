<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function array_any;
use function count;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;
use function str_contains;
use function str_ends_with;

/**
 * H3 — a class answers at most twenty questions.
 *
 * A class past the cap is usually two, and the seam is where the questions
 * change subject. Counted as the class declares them, constructor included,
 * which is what SonarCloud's S1448 counts at the same default. Tests are exempt:
 * a test support class is a table of fixtures.
 *
 * @implements Rule<ClassLike>
 */
final readonly class NoManyMethodsRule implements Rule
{
    /** How many methods a class may declare; public so the fixture proving this rule can build one more. */
    public const int THE_MOST_METHODS = 20;

    /** @param list<string> $allowIn files, relative to the repository, of a class whose methods a PHP protocol fixes */
    public function __construct(private array $allowIn = [])
    {
    }

    public function getNodeType(): string
    {
        return ClassLike::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (str_contains($scope->getFile(), '/tests/') || $this->isAllowed($scope->getFile())) {
            return [];
        }

        $methods = count($node->getMethods());

        if ($methods <= self::THE_MOST_METHODS) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'H3 — this %s declares %d methods, which is more than %d. It is usually two types, and the seam is where the questions change subject (H3).',
                $this->what($node),
                $methods,
                self::THE_MOST_METHODS,
            ))
                ->identifier('mutationGate.manyMethods')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    private function isAllowed(string $file): bool
    {
        return array_any($this->allowIn, static fn(string $allowed): bool => str_ends_with($file, sprintf('/%s', $allowed)));
    }

    private function what(ClassLike $node): string
    {
        return match (true) {
            $node instanceof Interface_ => 'interface',
            $node instanceof Enum_ => 'enum',
            $node instanceof Class_ => 'class',
            default => 'type',
        };
    }
}
