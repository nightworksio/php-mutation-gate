<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\PlannedOrders;
use NightWorksIO\MutationGate\Adapter\Pest\Recording\RecordLine;
use NightWorksIO\MutationGate\Core\Format\Node;
use NightWorksIO\MutationGate\Core\Format\NotInShape;
use NightWorksIO\MutationGate\Core\NotGiven;

/** The record a line of the results file holds. */
function orderedRecord(string $line): Node
{
    return Node::decode(rtrim($line));
}

it('knows how many tests a copy\'s own run was to run, and the digest of their order, where one run recorded them', function (): void {
    $order = hash('sha256', 'order');
    $orders = new PlannedOrders();
    $orders->ordered(orderedRecord(RecordLine::ordered('/m/a', 3, $order)));
    $orders->ordered(orderedRecord(RecordLine::ordered('/m/b', 2, $order)));
    $orders->ordered(orderedRecord(RecordLine::ordered('/m/b', 2, $order)));

    expect($orders->of('/m/a'))->toBe([3, $order])
        ->and($orders->of('/m/b'))->toEqual([NotGiven::value(), NotGiven::value()])
        ->and($orders->of('/m/c'))->toEqual([NotGiven::value(), NotGiven::value()]);
});

it('refuses a count below none and an order that is no SHA-256 digest', function (string $line): void {
    expect(static fn() => new PlannedOrders()->ordered(orderedRecord($line)))->toThrow(NotInShape::class);
})->with([
    'a negative count' => [fn(): string => RecordLine::ordered('/m/a', -1, hash('sha256', 'order'))],
    'a short order' => [fn(): string => RecordLine::ordered('/m/a', 3, 'abc')],
]);
