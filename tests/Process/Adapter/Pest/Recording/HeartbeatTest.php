<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Silence;
use Symfony\Component\Process\Process;

it('beats on the process\'s error output, and on nothing else', function (): void {
    $php = new Process([PHP_BINARY, '-r', sprintf(
        'require %s; NightWorksIO\MutationGate\Adapter\Pest\Recording\Heartbeat::onErrorOutput()->beat();',
        var_export(sprintf('%s/vendor/autoload.php', dirname(__DIR__, 5)), return: true),
    )]);
    $php->run();

    expect([$php->getOutput(), $php->getErrorOutput()])->toBe(['', Silence::BEAT]);
})->group('holds:src/Adapter/Pest/Recording/Heartbeat.php');
