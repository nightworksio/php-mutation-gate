<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function file_get_contents;
use function is_string;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;
use function str_contains;

/**
 * H10 — no line in `src` is longer than 120 characters.
 *
 * SonarCloud's S103 refuses a longer line in `src`. LongLines counts as S103
 * does, so the analyser refuses the line before the scan does. The scan raises
 * S103 on no test file, so neither does this.
 *
 * @implements Rule<FileNode>
 */
final readonly class NoLongLineRule implements Rule
{
    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! str_contains($scope->getFile(), '/src/') || str_contains($scope->getFile(), '/tests/')) {
            return [];
        }

        $code = file_get_contents($scope->getFile());
        $errors = [];

        foreach (LongLines::in(is_string($code) ? $code : '') as $line => $length) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                'H10 — this line is %d characters long, more than %d. SonarCloud\'s S103 refuses it: split it (H10).',
                $length,
                LongLines::LONGEST,
            ))
                ->identifier('mutationGate.longLine')
                ->line($line)
                ->build();
        }

        return $errors;
    }
}
