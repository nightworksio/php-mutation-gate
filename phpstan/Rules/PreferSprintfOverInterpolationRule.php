<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Scalar\InterpolatedString;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * H5, for interpolation: `"Tree {$path} is below"` interleaves the shape of the
 * string with its values exactly as concatenation does. A second class only
 * because a PHPStan rule declares one node type.
 *
 * @implements Rule<InterpolatedString>
 */
final class PreferSprintfOverInterpolationRule implements Rule
{
    public function getNodeType(): string
    {
        return InterpolatedString::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        return [
            RuleErrorBuilder::message(
                'H5 — build this with sprintf rather than interpolation. An interpolated string spreads its shape across the values going into it, and there is no single literal to read (H5).',
            )
                ->identifier('mutationGate.preferSprintf')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
