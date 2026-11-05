<?php

declare(strict_types=1);

namespace NightWorksIO\MutationGate\Adapter\Pest;

use function array_key_exists;
use function count;

use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordField;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\Mutant\OrderDigest;
use NightWorksIO\MutationGate\Core\NotGiven;

use function preg_match;

/**
 * How many tests each mutant's own run was to run once it ordered them, and
 * the digest of that order, by the mutated copy it ran on, as a run's
 * records say (ADR-0089). Of two runs on one copy, which was to run what
 * cannot be told, so neither is known.
 *
 * @internal
 */
final class PlannedOrders
{
    /** @var array<string, list<array{int, string}>> each own run's count and order, by the mutated copy it ran on */
    private array $orders = [];

    /**
     * An own run's count and order.
     *
     * @throws NotInShape
     */
    public function ordered(Node $record): void
    {
        $count = $record->field(RecordField::Count->value);
        $order = $record->field(RecordField::Order->value);

        if ($count->integer() < 0) {
            throw NotInShape::at($count->at(), 'a number of tests, which is never below 0');
        }

        if (preg_match(OrderDigest::PATTERN, $order->text()) !== 1) {
            throw NotInShape::at($order->at(), 'a SHA-256 digest in lowercase hex');
        }

        $this->orders[$record->field(RecordField::Mutated->value)->text()][] = [$count->integer(), $order->text()];
    }

    /**
     * How many tests the own run on this mutated copy was to run, and the
     * digest of their order, where one run recorded them.
     *
     * @return array{int|NotGiven, string|NotGiven}
     */
    public function of(string $mutated): array
    {
        return array_key_exists($mutated, $this->orders) && count($this->orders[$mutated]) === 1
            ? $this->orders[$mutated][0]
            : [NotGiven::value(), NotGiven::value()];
    }
}
