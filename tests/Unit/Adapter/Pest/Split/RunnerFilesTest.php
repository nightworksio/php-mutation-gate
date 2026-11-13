<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\RunnerFiles;
use NightWorksIO\MutationGate\Tests\Support\Scratch;

it('knows each file its command line names, as a value of an option or alone, by device and inode', function (): void {
    $directory = Scratch::directory();
    $progress = sprintf('%s/progress', $directory);
    $status = sprintf('%s/status', $directory);
    $other = sprintf('%s/other', $directory);

    foreach ([$progress, $status, $other] as $file) {
        file_put_contents($file, '');
    }

    $runner = RunnerFiles::namedBy(['worker.php', sprintf('--progress-file=%s', $progress), '--status-file', $status]);
    $writing = fopen($progress, 'ab');
    $held = is_resource($writing) && $runner->hold(fstat($writing));
    $linked = sprintf('%s/linked', $directory);
    symlink($status, $linked);

    expect([$runner->hold(stat($progress)), $runner->hold(stat($linked)), $runner->hold(stat($other)), $runner->hold(status: false)])
        ->toBe([true, true, false, false])
        ->and($held)->toBeTrue()
        ->and($runner->identities())->toHaveCount(2);

    if (is_resource($writing)) {
        fclose($writing);
    }
});

it('knows no file where its command line names none that exists', function (): void {
    expect(RunnerFiles::namedBy(['--filter=Money', '/no/such/file'])->identities())->toBe([]);
});
