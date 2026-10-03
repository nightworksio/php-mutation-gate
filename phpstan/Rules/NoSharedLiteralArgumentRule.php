<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function implode;

use NightWorksIO\MutationGate\PHPStan\Collectors\LiteralArguments;
use NightWorksIO\MutationGate\PHPStan\Rules\SharedLiterals\Declaration;
use NightWorksIO\MutationGate\PHPStan\Rules\SharedLiterals\Shared;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;

/**
 * D12 — a parameter two classes hand string literals takes an enum or a
 * shared constant.
 *
 * Two classes spelling the values of one parameter each keep a copy of what
 * it accepts: a closed set, which is an enum, or a shared value, which is a
 * constant both refer to. A method whose parameters take prose, a key or an
 * outside vocabulary from anywhere is named in `allowIn`, which only shrinks:
 * a run over every configured path refuses an entry no two classes need any
 * more. It reads calls under `src` to methods declared under `src`.
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class NoSharedLiteralArgumentRule implements Rule
{
    /**
     * @param array<string, list<string>> $allowIn         by class, the methods whose parameters take literals from anywhere
     * @param list<string>                $analysedPaths   the paths this run reads
     * @param list<string>                $configuredPaths the paths phpstan.neon names, which a run reads when it is given none
     */
    public function __construct(
        private ReflectionProvider $reflection,
        private array $allowIn = [],
        private array $analysedPaths = [],
        private array $configuredPaths = [],
    ) {
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $collected = $node->get(LiteralArguments::class);
        $allowed = ByClass::named($this->allowIn);

        return [
            ...array_map($this->shared(...), Shared::among($collected, $allowed)),
            ...array_map($this->unneeded(...), $this->analysedPaths === $this->configuredPaths ? $this->declared(Shared::unneeded($collected, $allowed)) : []),
        ];
    }

    private function shared(Shared $shared): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'D12 — %s() takes $%s as a string literal from %s: %s. What two classes spell is a closed set or a shared value: take an enum, or a constant they share (D12).',
            $shared->method,
            $shared->parameter,
            implode(', ', $shared->callers),
            implode(', ', $shared->literals),
        ))
            ->identifier('mutationGate.literalSharedByClasses')
            ->file($shared->file)
            ->line($shared->line)
            ->build();
    }

    /**
     * The methods whose class the analyser knows, which an error can point at; TheExemptionsAreRealTest refuses the others.
     *
     * @param list<string> $methods
     *
     * @return list<string>
     */
    private function declared(array $methods): array
    {
        return array_values(array_filter($methods, fn(string $method): bool => $this->reflection->hasClass(explode('::', $method, 2)[0])));
    }

    private function unneeded(string $method): IdentifierRuleError
    {
        [$class, $name] = explode('::', $method, 2);
        $declaring = $this->reflection->getClass($class);

        return RuleErrorBuilder::message(sprintf(
            'D12 — phpstan.neon lets %s() take string literals from any class, and no two classes hand it one any more. Take it off allowIn: the list only shrinks (D12).',
            $method,
        ))
            ->identifier('mutationGate.unneededLiteralExemption')
            ->file((string) $declaring->getFileName())
            ->line(Declaration::lineOf($declaring, $name))
            ->build();
    }
}
