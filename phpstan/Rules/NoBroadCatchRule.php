<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function array_any;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function in_array;

use PhpParser\Node;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Name;
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
 * answer is an outcome, not a catch. The files named in `allowIn` run code the
 * gate does not own, whose every failure the catch turns into an outcome.
 *
 * @implements Rule<Catch_>
 */
final readonly class NoBroadCatchRule implements Rule
{
    private const array EVERYTHING = ['throwable', 'exception', '\\throwable', '\\exception'];

    /** @param list<string> $allowIn files, relative to the repository, that catch everything a project's own code may throw */
    public function __construct(private array $allowIn = [])
    {
    }

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

        if (ListedFiles::hold($this->allowIn, $scope->getFile()) || $this->rethrows($node)) {
            return [];
        }

        $broad = array_filter($node->types, static fn(Name $type): bool => in_array(strtolower($type->toString()), self::EVERYTHING, strict: true));

        return array_map(
            fn(Name $type): IdentifierRuleError => $this->error($node, sprintf('catches %s without rethrowing', $type->toString())),
            array_slice(array_values($broad), 0, 1),
        );
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
