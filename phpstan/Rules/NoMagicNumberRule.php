<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function array_map;
use function in_array;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;
use function str_contains;

/**
 * D6 — a number in a method body has a name.
 *
 * `600` seconds per shard, `20000` proofs kept and `100` as a floor are
 * decisions, and a decision written as a literal cannot be found by what it
 * means. Only method bodies are read, so a class constant or an enum case is
 * the cure. 0, 1 and 2 mean empty, one and a pair, and an array index is a
 * position rather than a quantity; tests keep their numbers.
 *
 * @implements Rule<ClassMethod>
 */
final class NoMagicNumberRule implements Rule
{
    /** Numbers whose name would be the number. */
    private const array PLAIN = [0, 1, 2];

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (str_contains($scope->getFile(), '/tests/')) {
            return [];
        }

        $found = [];

        foreach ($this->literalsIn($node->stmts ?? []) as $literal) {
            $found[] = RuleErrorBuilder::message(sprintf(
                'D6 — give %s a name. A number written into a method body is a decision nobody can search for by what it means. Declare it as a class constant or an enum case (D6).',
                $this->render($literal),
            ))
                ->identifier('mutationGate.magicNumber')
                ->line($literal->getStartLine())
                ->build();
        }

        return $found;
    }

    /**
     * Numeric literals in these statements that stand in for a name.
     *
     * @param array<Node> $statements
     *
     * @return list<Int_|Float_>
     */
    private function literalsIn(array $statements): array
    {
        $finder = new NodeFinder();
        $positions = array_map(
            static fn(ArrayDimFetch $fetch): ?Node => $fetch->dim,
            $finder->findInstanceOf($statements, ArrayDimFetch::class),
        );
        $found = [];

        foreach ($finder->find($statements, static fn(Node $node): bool => $node instanceof Int_ || $node instanceof Float_) as $literal) {
            if (($literal instanceof Int_ || $literal instanceof Float_) && ! $this->isPlain($literal) && ! in_array($literal, $positions, strict: true)) {
                $found[] = $literal;
            }
        }

        return $found;
    }

    private function isPlain(Int_|Float_ $literal): bool
    {
        if ($literal instanceof Int_) {
            return in_array($literal->value, self::PLAIN, strict: true);
        }

        return in_array((int) $literal->value, self::PLAIN, strict: true) && (float) (int) $literal->value === $literal->value;
    }

    private function render(Int_|Float_ $literal): string
    {
        return $literal instanceof Int_ ? sprintf('%d', $literal->value) : sprintf('%s', $literal->value);
    }
}
