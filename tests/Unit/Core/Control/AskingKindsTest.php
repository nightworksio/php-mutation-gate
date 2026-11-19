<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Control\AskingKinds;
use NightWorksIO\MutationGate\Core\Control\ControlKind;

it('counts the kinds it asks for, in the order they are given', function (): void {
    $kinds = AskingKinds::of(ControlKind::Kills, ControlKind::Timeout);

    expect(count($kinds))->toBe(2)
        ->and([...$kinds])->toBe([ControlKind::Kills, ControlKind::Timeout])
        ->and(count(AskingKinds::of()))->toBe(0);
});
