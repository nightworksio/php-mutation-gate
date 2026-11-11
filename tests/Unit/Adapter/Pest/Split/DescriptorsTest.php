<?php

declare(strict_types=1);

use NightWorksIO\MutationGate\Adapter\Pest\Split\Descriptors;

it('shares nothing it held at the start, and shares a socket opened since, until it closes', function (): void {
    $start = Descriptors::held();
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $opened = Descriptors::held()->shares($start);

    foreach (is_array($pair) ? $pair : [] as $socket) {
        fclose($socket);
    }

    expect($opened)->toBeTrue()
        ->and(Descriptors::held()->shares($start))->toBeFalse();
})->skip(fn(): bool => Descriptors::held()->shares(Descriptors::held()), 'the runner holds a file open for writing, so this process forks no test');

it('shares a file open for writing, held since the start or not, and no file open only for reading', function (): void {
    $start = Descriptors::held();
    $read = fopen(__FILE__, 'r');
    $reading = Descriptors::held()->shares($start);
    $written = tmpfile();
    $writing = Descriptors::held()->shares($start);
    $sinceTheStart = Descriptors::held()->shares(Descriptors::held());

    foreach ([$read, $written] as $file) {
        if (is_resource($file)) {
            fclose($file);
        }
    }

    expect([$reading, $writing, $sinceTheStart])->toBe([false, true, true]);
})->skip(fn(): bool => Descriptors::held()->shares(Descriptors::held()), 'the runner holds a file open for writing, so this process forks no test');

it('shares a file-backed SQLite database a test opened, where the system lists its descriptors', function (): void {
    $start = Descriptors::held();
    $path = tempnam(sys_get_temp_dir(), 'descriptors');
    $database = new PDO(sprintf('sqlite:%s', $path));
    $database->exec('CREATE TABLE held (value INTEGER)');
    $shared = Descriptors::held()->shares($start);
    unset($database);
    unlink($path);

    expect($shared)->toBeTrue();
})->skip(
    fn(): bool => ! is_dir('/proc/self/fd') && ! function_exists('outbreak_descriptors'),
    'only the system\'s list of descriptors, or outbreak.so, sees a database client\'s own',
);
