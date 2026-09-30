<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function preg_match;
use function sprintf;

/**
 * H11 — a class, interface, trait or enum is named in letters, starting with a
 * capital.
 *
 * SonarCloud's S101, with the pattern of the PSR-2 profile this project is
 * scanned with, refuses a class name with a digit or an underscore in it.
 * This holds interfaces, traits and enums to the same pattern, so one spelling
 * rule covers every name a file declares. An anonymous class has no name to
 * spell.
 *
 * @implements Rule<ClassLike>
 */
final readonly class LettersOnlyClassNameRule implements Rule
{
    /** SonarCloud's S101 pattern in the PSR-2 profile. */
    private const string PATTERN = '/^[A-Z][a-zA-Z]*$/';

    public function getNodeType(): string
    {
        return ClassLike::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        $name = $node->name?->toString() ?? '';

        if ($name === '' || $node->getAttribute('anonymousClass') === true || preg_match(self::PATTERN, $name) === 1) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'H11 — name %s in letters only, starting with a capital. SonarCloud\'s S101 refuses a digit or an underscore in a class name (H11).',
                $name,
            ))
                ->identifier('mutationGate.classNameLettersOnly')
                ->line($node->getStartLine())
                ->build(),
        ];
    }
}
