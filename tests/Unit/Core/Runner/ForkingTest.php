<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Runner\Forking;

it('forks only where this PHP holds both pcntl and posix', function (): void {
    expect(Forking::isAvailable())->toBe(extension_loaded('pcntl') && extension_loaded('posix'));
});

it('forks a child beside the gate wherever it can fork, the gate not running from its PHAR', function (): void {
    expect(Phar::running(returnPhar: false))->toBe('')
        ->and(Forking::besideTheGate())->toBe(Forking::isAvailable());
});
