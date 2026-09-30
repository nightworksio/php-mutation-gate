<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use NightWorksIO\MutationGate\PHPStan\Rules\ListLayout\Items;
use NightWorksIO\MutationGate\PHPStan\Rules\ListLayout\Source;
use PhpParser\Node;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\LineRuleError;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;

/**
 * H9 — how the lists of one file are laid out, judged the way SonarCloud's
 * S1808 judges them.
 *
 * A call's arguments, and a function's, method's or closure's parameters, are
 * either all on one line, where only an array literal or a `function` closure
 * may span lines, or fully split: the first item on the next line, one item
 * per line, each one indent past the line the list is anchored to, and the `)`
 * on a line of its own unless the last item ends in one. Pint accepts a list
 * whose last argument is a call spanning lines; S1808 does not.
 */
final readonly class ListLayout
{
    private const string IDENTIFIER = 'mutationGate.listLayout';

    private function __construct(private Source $source)
    {
    }

    public static function of(string $code): self
    {
        return new self(Source::of($code));
    }

    /** @return list<IdentifierRuleError&LineRuleError> */
    public function judge(Node $node): array
    {
        $errors = [];

        foreach (Items::of($node, $this->source) as $list) {
            if ($list->isShort() || $list->isOnOneLine()) {
                continue;
            }

            $errors = [...$errors, ...$this->placement($list), ...$this->closer($list)];
        }

        return $errors;
    }

    /** @return list<IdentifierRuleError&LineRuleError> */
    private function placement(Items $list): array
    {
        if ($list->isSplitWrongly()) {
            return [$this->error(sprintf(
                'H9 — put every item of this list on line %d, or split it: the first on the next line, one per line, indented %d spaces. SonarCloud\'s S1808 refuses any other shape, and Pint lets this one through (H9).',
                $list->anchorLine(),
                $list->column(),
            ), $list->firstLine())];
        }

        if ($list->isMisaligned()) {
            return [$this->error(sprintf(
                'H9 — indent every item of this list %d spaces, one indent past line %d, as SonarCloud\'s S1808 requires (H9).',
                $list->column(),
                $list->anchorLine(),
            ), $list->firstLine())];
        }

        return [];
    }

    /** @return list<IdentifierRuleError&LineRuleError> */
    private function closer(Items $list): array
    {
        if (! $list->crowdsItsCloser()) {
            return [];
        }

        return [$this->error('H9 — move the closing parenthesis of this list onto a line of its own, as SonarCloud\'s S1808 requires (H9).', $list->closerLine())];
    }

    private function error(string $message, int $line): IdentifierRuleError&LineRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier(self::IDENTIFIER)
            ->line($line)
            ->build();
    }
}
