<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Command;
use NightWorksIO\MutationGate\Adapter\Pest\LongestFirst;
use NightWorksIO\MutationGate\Core\Time\Seconds;
use NightWorksIO\MutationGate\Core\Time\Unlimited;

it('starts the commands of the longest limit first, an unlimited one before all, and hands each answer back in the order given', function (): void {
    $order = LongestFirst::of(
        Command::of('short')->within(Seconds::of(1.0)),
        Command::of('unlimited')->within(Unlimited::time()),
        Command::of('long')->within(Seconds::of(5.0)),
    );

    expect($order->sorted(['short', 'unlimited', 'long']))->toBe(['unlimited', 'long', 'short'])
        ->and($order->restored(['for unlimited', 'for long', 'for short']))->toBe(['for short', 'for unlimited', 'for long']);
});

it('hands back the answers of the commands given before the first that none answers, where fewer answer', function (): void {
    $shortFirst = LongestFirst::of(Command::of('short')->within(Seconds::of(1.0)), Command::of('long')->within(Seconds::of(5.0)));
    $longFirst = LongestFirst::of(Command::of('long')->within(Seconds::of(5.0)), Command::of('short')->within(Seconds::of(1.0)));

    expect($shortFirst->restored(['for long']))->toBe([])
        ->and($longFirst->restored(['for long']))->toBe(['for long'])
        ->and($longFirst->restored([]))->toBe([])
        ->and(LongestFirst::asGiven(Command::of('a'), Command::of('b'))->sorted(['a', 'b']))->toBe(['a', 'b']);
});
