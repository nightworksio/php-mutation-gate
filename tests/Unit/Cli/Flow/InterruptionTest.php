<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Interruption;

it('has arrived once what it watches says so, and never where it watches nothing', function (): void {
    $said = false;
    $interruption = Interruption::when(static function () use (&$said): bool {
        return $said;
    });

    $before = $interruption->arrived();
    $said = true;

    expect($before)->toBeFalse()
        ->and($interruption->arrived())->toBeTrue()
        ->and(new Interruption()->arrived())->toBeFalse();
});
