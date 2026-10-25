<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Core\Native\Command;

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
