<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Cli\Discovery\Declared;

it('is a class a package names as an extension', function (): void {
    $declared = new Declared('acme/gate-slack', 'Acme\\GateSlack\\SlackExtension');

    expect($declared->origin)->toBe('acme/gate-slack')
        ->and($declared->class)->toBe('Acme\\GateSlack\\SlackExtension');
});
