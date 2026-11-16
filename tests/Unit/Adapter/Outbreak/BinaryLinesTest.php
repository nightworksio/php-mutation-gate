<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Outbreak\BinaryLines;
use NightWorksIO\MutationGate\Core\Config\OutbreakServe;
use NightWorksIO\MutationGate\Core\NotGiven;
use NightWorksIO\MutationGate\Core\Time\Seconds;

it('launches the PHP daemon under outbreak serve, detached, with the settings\' snapshot, sync and debounce', function (): void {
    $serve = OutbreakServe::of(enabled: true, snapshotEvery: Seconds::of(30.0), syncEvery: Seconds::of(1.5), debounce: Seconds::of(0.05));

    expect(new BinaryLines('/bin/outbreak', '/project', $serve)->serving(['nice', '-n', '10']))->toBe([
        'nice',
        '-n',
        '10',
        '/bin/outbreak',
        'serve',
        '/project',
        '--detach',
        '--snapshot-every',
        '30.000000',
        '--sync-every',
        '1.500000',
        '--debounce',
        '0.050000',
        '--',
    ]);
});

it('launches the PHP daemon alone where no binary is in use or outbreak serve is off', function (bool $binaryInUse, bool $enabled): void {
    $binary = $binaryInUse ? '/bin/outbreak' : NotGiven::value();

    expect(new BinaryLines($binary, '/project', OutbreakServe::of(enabled: $enabled))->serving(['nice']))->toBe(['nice']);
})->with([
    'no binary' => [false, true],
    'serve off' => [true, false],
]);

it('runs outbreak lsp on the gate\'s script, its PHP and the severity, with the watch options after the double dash, and none without a binary', function (): void {
    $lines = static fn(string|NotGiven $binary): array|NotGiven => new BinaryLines($binary, '/project', OutbreakServe::of())
        ->editing('/project/bin/mutation-gate', '/usr/bin/php', 'warning', ['--tree=src', '--quick']);

    expect($lines('/bin/outbreak'))->toBe([
        '/bin/outbreak',
        'lsp',
        '--gate',
        '/project/bin/mutation-gate',
        '--php',
        '/usr/bin/php',
        '--severity',
        'warning',
        '--',
        '--tree=src',
        '--quick',
    ])->and($lines(NotGiven::value()))->toEqual(NotGiven::value());
});
