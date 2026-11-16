<?php

declare(strict_types=1);

it('adds two numbers in a process of its own', function (): void {
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/spawned.php'], [1 => ['pipe', 'w']], $pipes);
    $printed = is_resource($process) ? stream_get_contents($pipes[1]) : '';

    if (is_resource($process)) {
        fclose($pipes[1]);
        proc_close($process);
    }

    expect($printed)->toBe('5');
});
