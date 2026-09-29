<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function array_any;
use function in_array;

use PhpParser\Node;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;
use function strtolower;

/**
 * C6 — a catch names what it caught and does something about it.
 *
 * An empty body discards the one thing that was about to explain a failure. A
 * catch of `Throwable` or `Exception` that does not rethrow absorbs the failures
 * nobody anticipated along with the one that was expected. Across a port the
 * answer is an outcome, not a catch.
 *
 * @implements Rule<Catch_>
 */
final class NoBroadCatchRule implements Rule
{
    private const array EVERYTHING = ['throwable', 'exception', '\\throwable', '\\exception'];

    public function getNodeType(): string
    {
        return Catch_::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->stmts === []) {
            return [$this->error($node, 'swallows what it caught')];
        }

        foreach ($node->types as $type) {
            if (in_array(strtolower($type->toString()), self::EVERYTHING, strict: true) && ! $this->rethrows($node)) {
                return [$this->error($node, sprintf('catches %s without rethrowing', $type->toString()))];
            }
        }

        return [];
    }

    /** Whether the body throws again; `throw` arrives wrapped in an expression statement. */
    private function rethrows(Catch_ $node): bool
    {
        return array_any($node->stmts, fn(Stmt $statement): bool => $statement instanceof Expression && $statement->expr instanceof Throw_);
    }

    private function error(Catch_ $node, string $what): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'C6 — this catch %s. An empty body discards what was about to explain the failure, and catching Throwable absorbs the failures nobody anticipated alongside the one that was expected. Name the exception this code knows how to answer; across a port, answer with an outcome instead (C6, C1).',
            $what,
        ))
            ->identifier('mutationGate.broadCatch')
            ->line($node->getStartLine())
            ->build();
    }
}
