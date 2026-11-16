<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Flow\Controlling;
use NightWorksIO\MutationGate\Core\Runner\Forking;
use NightWorksIO\MutationGate\Core\Runner\Pool;
use NightWorksIO\MutationGate\Tests\Support\Configs;

it('controls kills where controls.kills is on and no split stream judges the mutants', function (): void {
    $split = ['runner' => 'pest', 'pest' => ['patch' => true], 'splitStream' => ['enabled' => true]];

    expect(Controlling::controlsKills(Configs::settings(['runner' => 'phpunit'])))->toBeTrue()
        ->and(Controlling::controlsKills(Configs::settings(['runner' => 'phpunit', 'controls' => ['kills' => false]])))->toBeFalse()
        ->and(Controlling::controlsKills(Configs::settings(['runner' => 'pest', 'pest' => ['patch' => true]])))->toBeTrue()
        ->and(Controlling::controlsKills(Configs::settings($split)))->toBe(! Forking::isAvailable());
});

it('bounds a kill\'s control by timeouts.controlMost in place of timeouts.most', function (): void {
    $settings = Configs::settings(['runner' => 'phpunit', 'timeouts' => ['controlMost' => 777]]);

    expect(Controlling::killBounds($settings, Pool::single())->most()->seconds())->toBe(777.0);
});
