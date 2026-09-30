<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function array_any;
use function array_key_exists;

use NightWorksIO\MutationGate\PHPStan\Rules\Mixed\MixedInDocBlock;
use NightWorksIO\MutationGate\PHPStan\Rules\Mixed\MixedInType;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;
use function str_contains;
use function str_ends_with;

/**
 * D10 — `mixed` is read at the edge and nowhere else.
 *
 * A value arrives untyped in a few places: a JSON or YAML decoder, a file PHP
 * `require`s, a command line. Each is a decoder, named in `decoders`, and it
 * hands on typed values. A class whose signatures a PHP protocol fixes holds
 * what PHP hands it, such as a resource, which has no native type; each is
 * named in `protocols`. Anywhere else, `mixed` in a native type or a doc
 * block, a private member's, a closure's or inside a generic, is a value
 * nobody typed. The files that still hold one are named in `allowIn`, which
 * only shrinks. It reads `src` and `tests/Support`.
 *
 * @implements Rule<Node>
 */
final class NoMixedOutsideDecodersRule implements Rule
{
    /** @var array<int, true> the doc blocks of the file being read that are reported already, by where they start */
    private array $reported = [];

    private string $file = '';

    /**
     * @param list<string> $decoders  files, relative to the repository, that read untrusted input into typed values
     * @param list<string> $allowIn   files, relative to the repository, that hold a `mixed` still to be typed
     * @param list<string> $protocols files, relative to the repository, of classes whose signatures a PHP protocol fixes
     */
    public function __construct(
        private readonly array $decoders = [],
        private readonly array $allowIn = [],
        private readonly array $protocols = [],
    ) {
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->isRead($scope->getFile())) {
            return [];
        }

        $where = match (true) {
            $this->writesMixedInDocBlock($node, $scope->getFile()) => 'this doc block',
            MixedInType::of($node) => 'this type',
            default => '',
        };

        if ($where === '') {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'D10 — %s says mixed, which is a value nobody typed. Name what arrives; only a decoder of untrusted input takes mixed, and it hands on typed values (D10, D3).',
                $where,
            ))
                ->identifier('mutationGate.mixedOutsideDecoders')
                ->line($node->getStartLine())
                ->build(),
        ];
    }

    private function isRead(string $file): bool
    {
        $inScope = (str_contains($file, '/src/') && ! str_contains($file, '/tests/')) || str_contains($file, '/tests/Support/');

        return $inScope && ! $this->isNamedIn([...$this->decoders, ...$this->allowIn, ...$this->protocols], $file);
    }

    /** @param list<string> $files */
    private function isNamedIn(array $files, string $file): bool
    {
        return array_any($files, static fn(string $named): bool => str_ends_with($file, sprintf('/%s', $named)));
    }

    /** Whether a node's own doc block, not yet reported, writes mixed. */
    private function writesMixedInDocBlock(Node $node, string $file): bool
    {
        $docBlock = $node->getDocComment();

        if (!$docBlock instanceof Doc || ! MixedInDocBlock::in($docBlock->getText())) {
            return false;
        }

        if ($file !== $this->file) {
            $this->file = $file;
            $this->reported = [];
        }

        $seen = array_key_exists($docBlock->getStartFilePos(), $this->reported);
        $this->reported[$docBlock->getStartFilePos()] = true;

        return ! $seen;
    }
}
