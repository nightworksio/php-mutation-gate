<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function array_filter;
use function array_values;
use function count;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;
use function str_contains;

/**
 * H8 — a method leaves by at most three doors.
 *
 * A method with four returns is usually two: one deciding which situation it is
 * in and one answering for it. Counted per function, and a closure counts as its
 * own. Tests are exempt.
 *
 * @implements Rule<FunctionLike>
 */
final class NoManyReturnsRule implements Rule
{
    /** A guard, a refusal and an answer; SonarCloud's S1142 default. */
    private const int THE_MOST_DOORS = 3;

    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (str_contains($scope->getFile(), '/tests/')) {
            return [];
        }

        $returns = count($this->returnsDirectlyIn($node));

        if ($returns <= self::THE_MOST_DOORS) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'H8 — this %s returns from %d places, which is more than %d. Split it where the question changes: one half decides which situation it is in, the other answers for it (H8).',
                $this->what($node),
                $returns,
                self::THE_MOST_DOORS,
            ))
                ->identifier('mutationGate.manyReturns')
                ->build(),
        ];
    }

    /**
     * The returns this function owns, without those of a closure inside it.
     *
     * @return list<Return_>
     */
    private function returnsDirectlyIn(FunctionLike $node): array
    {
        $finder = new NodeFinder();
        $found = $finder->findInstanceOf($node->getStmts() ?? [], Return_::class);

        foreach ($finder->findInstanceOf($node->getStmts() ?? [], FunctionLike::class) as $nested) {
            foreach ($finder->findInstanceOf($nested->getStmts() ?? [], Return_::class) as $theirs) {
                $found = array_filter($found, static fn(Return_ $ours): bool => $ours !== $theirs);
            }
        }

        return array_values($found);
    }

    private function what(FunctionLike $node): string
    {
        return match (true) {
            $node instanceof ClassMethod => 'method',
            $node instanceof Closure, $node instanceof ArrowFunction => 'closure',
            default => 'function',
        };
    }
}
