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

it('runs PHP with the gate\'s PHAR prepended, from the PHAR, unless it runs the PHAR itself, and as it is otherwise', function (): void {
    expect(Command::phpFrom(OwnArchive::at('/usr/local/bin/mutation-gate'), 'vendor/bin/phpunit')->arguments())->toBe([
        PHP_BINARY,
        '-d',
        'auto_prepend_file=/usr/local/bin/mutation-gate',
        'vendor/bin/phpunit',
    ])
        ->and(Command::phpFrom(OwnArchive::at('/usr/local/bin/mutation-gate'), '/usr/local/bin/mutation-gate', 'mutation-gate-phpspec')->arguments())
        ->toBe([PHP_BINARY, '/usr/local/bin/mutation-gate', 'mutation-gate-phpspec'])
        ->and(Command::phpFrom(NotGiven::value(), 'vendor/bin/phpunit')->arguments())->toBe([PHP_BINARY, 'vendor/bin/phpunit'])
        ->and(Command::php('vendor/bin/phpunit'))->toEqual(Command::phpFrom(NotGiven::value(), 'vendor/bin/phpunit'));
});
