<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Native\Command;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\OwnArchive;

it('runs its PHP through the launcher, which is handed the file of the peak, then the PHP and its arguments', function (): void {
    $command = Command::php('vendor/bin/phpunit', '--filter=doubles')->launchedBy('launcher.php', 'peak.txt');

    expect($command->arguments())->toBe([
        PHP_BINARY,
        'launcher.php',
        'peak.txt',
        PHP_BINARY,
        'vendor/bin/phpunit',
        '--filter=doubles',
    ]);
});

it('runs PHP loading the gate\'s PHAR\'s autoloader first, from the PHAR, and as it is otherwise', function (): void {
    expect(Command::phpFrom(OwnArchive::at('/opt/mutation-gate.phar'), 'vendor/bin/phpunit')->arguments())->toBe([
        PHP_BINARY,
        '-d',
        'auto_prepend_file=phar:///opt/mutation-gate.phar/vendor/autoload.php',
        'vendor/bin/phpunit',
    ])
        ->and(Command::phpFrom(NotGiven::value(), 'vendor/bin/phpunit')->arguments())->toBe([PHP_BINARY, 'vendor/bin/phpunit'])
        ->and(Command::php('vendor/bin/phpunit'))->toEqual(Command::phpFrom(NotGiven::value(), 'vendor/bin/phpunit'));
});
