<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function file_get_contents;
use function is_string;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;

use function str_contains;

/**
 * H9 — a list is on one line or fully split, as SonarCloud's S1808 requires.
 *
 * S1808 reads the main sources only, so this reads `src` and leaves the tests
 * and these rules alone. The judgement is ListLayout's; this reads the file it
 * judges once, and keeps it while the analyser walks that file.
 *
 * @implements Rule<Node>
 */
final class ListLayoutRule implements Rule
{
    private string $file = '';

    private ListLayout $layout;

    public function __construct()
    {
        $this->layout = ListLayout::of('');
    }

    public function getNodeType(): string
    {
        return Node::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $file = $scope->getFile();

        if (! str_contains($file, '/src/') || str_contains($file, '/tests/')) {
            return [];
        }

        if ($file !== $this->file) {
            $code = file_get_contents($file);
            $this->file = $file;
            $this->layout = ListLayout::of(is_string($code) ? $code : '');
        }

        return $this->layout->judge($node);
    }
}
