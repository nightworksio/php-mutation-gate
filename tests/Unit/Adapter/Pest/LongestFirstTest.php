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

it('hands back as they are answers that are not one for each command', function (): void {
    $order = LongestFirst::of(Command::of('short')->within(Seconds::of(1.0)), Command::of('long')->within(Seconds::of(5.0)));

    expect($order->restored(['only one']))->toBe(['only one'])
        ->and(LongestFirst::asGiven(Command::of('a'), Command::of('b'))->sorted(['a', 'b']))->toBe(['a', 'b']);
});
