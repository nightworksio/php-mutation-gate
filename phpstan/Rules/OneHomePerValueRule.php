<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\PHPStan\Rules;

use function array_map;
use function implode;

use NightWorksIO\MutationGate\PHPStan\Collectors\ConstantValues;
use NightWorksIO\MutationGate\PHPStan\Rules\OneHome\Clash;
use NightWorksIO\MutationGate\PHPStan\Rules\OneHome\Home;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;

/**
 * D9 — one value has one home.
 *
 * The same value declared as a constant in one class and again in another is
 * one decision kept in two places, and a change to one leaves the other
 * behind. The value keeps one home and the other class refers to it. An enum
 * case is a home too, so a constant spelling a case's value is reported; two
 * enums sharing a word are left to D4. Where two constants hold the same value
 * by coincidence, meaning different things, each is named in `coincidences`
 * in `phpstan.neon`. A constant still to refer to its value's one home is
 * named in `awaiting`, which only shrinks.
 *
 * @implements Rule<CollectedDataNode>
 */
final readonly class OneHomePerValueRule implements Rule
{
    /**
     * @param array<string, list<string>> $coincidences by class, the constants whose value only happens to match another's
     * @param array<string, list<string>> $awaiting     by class, the constants still to refer to their value's one home
     */
    public function __construct(private array $coincidences = [], private array $awaiting = [])
    {
    }

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        return array_map(
            static fn(Clash $clash): IdentifierRuleError => RuleErrorBuilder::message(sprintf(
                'D9 — %s holds %s, and so does %s. One value has one home: keep it where it belongs and refer to it from the others (D9).',
                $clash->home->name,
                $clash->home->value,
                implode(', ', array_map(static fn(Home $other): string => $other->name, $clash->others)),
            ))
                ->identifier('mutationGate.valueWithTwoHomes')
                ->file($clash->home->file)
                ->line($clash->home->line)
                ->build(),
            Clash::among($node->get(ConstantValues::class), [...ByClass::named($this->coincidences), ...ByClass::named($this->awaiting)]),
        );
    }
}
